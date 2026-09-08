# Auditoría de seguridad y endurecimiento

Auditoría hecha el 2026-09-08 sobre `codex/ecuapos-saas`, encima de `68b45c72`,
a raíz de la pregunta "¿aguanta un ataque de fuerza bruta al login?". El login
resultó estar bien; lo grave estaba en otro lado.

Este documento es el traspaso: qué se encontró, qué quedó arreglado y qué no.

## Lo que ya estaba bien (no tocar)

- `/api/login` con `throttle:5,1`; `onboarding/register` 3/min; recuperación de
  contraseña 5/min. La fuerza bruta online no es un camino viable.
- Todo intento fallido se registra en `login_logs` con IP y user-agent, exista
  el correo o no.
- El login devuelve el mismo mensaje para usuario inexistente y contraseña
  incorrecta: no se pueden enumerar usuarios desde ahí.
- bcrypt con 10 rondas. Tokens de Sanctum con vencimiento explícito a 2 horas
  (`session_token_expiration`), no eternos.
- Ningún `whereRaw` del proyecto interpola entrada del usuario — se revisaron
  los 18. El `$threshold` de `ManageStockAPIController` y `StockReportExport`
  parece interpolación pero es una constante SQL, no entrada. **No hay
  inyección SQL.**
- Ordenamientos con lista blanca (`ManageStockAPIController::applySort`,
  `AllowedSort` de Spatie).
- Cero `dangerouslySetInnerHTML` en el frontend: superficie de XSS mínima.
- `is_super_admin` se descarta en los tres puntos de escritura de
  `UserRepository` (crear, actualizar, perfil), con test en
  `CriticalTenantIsolationTest`.
- `/api/super-admin/backup/download` correctamente detrás de `auth:sanctum` +
  `super.admin`, y el dump se borra tras enviarse (`deleteFileAfterSend`).

---

## 🔴 Crítico — ARREGLADO: archivos privados del SRI dentro del docroot

### El problema

`config/filesystems.php` define el disco `local` con
`'root' => public_path('uploads')`. Todo lo guardado con
`Storage::disk('local')` quedaba servido por HTTP **sin autenticación**.

Ahí vivían dos cosas:

1. **Los certificados `.p12`** (`SriConfigController::subirCertificado`), o sea
   la firma electrónica legal del negocio. Además el nombre se generaba con
   `uniqid('cert_')`, que es la marca de tiempo en hexadecimal: adivinable para
   quien sepa aproximadamente cuándo se subió.
2. **Los RIDE en PDF** (`SriRideService`), con nombre y cédula del cliente,
   montos y RUC del emisor. El nombre del archivo es la clave de acceso, un
   número de 49 dígitos con estructura conocida (fecha + RUC + secuencial):
   enumerable por cualquiera que tenga el RUC del negocio.

Verificado empíricamente antes del arreglo: se colocó un archivo en
`public/uploads/certificados/` y se descargó con `HTTP 200`.

### El arreglo

- `SriConfigController::CERT_DISK` y `SriRideService::RIDE_DISK` apuntan ahora a
  `saas_private` (root `storage/app/private`), el mismo disco que Codex ya había
  creado para los comprobantes de pago.
- `SriFirmaService` lee de ahí.
- Los certificados nuevos se nombran con `Str::random(40)` en vez de `uniqid()`.
- Migración `2026_09_08_110000_move_sri_private_files_out_of_the_webroot`: mueve
  los archivos ya subidos, renombra los certificados y actualiza el setting
  `sri_certificado_path` de cada tienda. Escribe el archivo destino **antes** de
  tocar el setting, para que un fallo a mitad de camino no deje a una tienda sin
  certificado. Borra los directorios públicos al terminar, huérfanos incluidos.
- La migración **no tiene `down()`** a propósito: devolver estos archivos al
  docroot sería reintroducir la exposición.

Verificado después: los dos archivos devuelven `HTTP 404`.

**El disco `local` sigue apuntando a `public/uploads` y así debe quedarse** —
las imágenes de productos se sirven desde ahí. El error no era el disco, era
usarlo para archivos privados. `SecurityHardeningTest` fija las dos mitades.

---

## 🟠 Alto — ARREGLADO: dependencias con CVEs

`composer audit` reportaba **91 advisories sobre 21 paquetes**, entre ellos
críticas en `phpoffice/phpspreadsheet` (2 críticas + 15 altas), que es lo que
parsea los Excel que suben los usuarios en `ProductImport` — entrada directa de
atacante a una librería con RCE conocido.

`composer update` dentro de las restricciones existentes (no hizo falta tocar
`composer.json`) dejó el conteo en **11 advisories sobre 3 paquetes**, sin
ninguna crítica. Las 140 pruebas siguen pasando.

Movimientos principales: `phpspreadsheet` 1.29.0 → 1.30.6, `dompdf` 2.0.3 →
2.0.8, `laravel/framework` 10.25.1 → 10.50.3, `medialibrary` 10.13 → 10.15.

En npm, `npm audit fix` (sin `--force`) bajó de 38 a 19: de 4 críticas y 10
altas a 1 y 1.

### Lo que queda, y por qué no se tocó

| Paquete | Severidad | Requiere | Por qué se dejó |
|---|---|---|---|
| `laravel/framework` | 1 alta (CRLF en la regla `email`) | ≥ 12.60 | Migración de Laravel 10 → 12. No se hace de paso en un parche de seguridad. |
| `spatie/laravel-medialibrary` | 1 alta (bypass de restricción de subida) | ≥ 11.23 | Salto de major con cambios de API en conversiones. **Es la más relevante de las tres** — toca subida de archivos — y la que yo evaluaría primero. |
| `dompdf/dompdf` | 2 bajas, 4 medias | major | Riesgo bajo; genera los RIDE con plantillas propias, no con HTML de terceros. |
| `axios` (npm) | alta | 0.25 → 1.20 (major) | Cambia la forma de los errores; la app tiene interceptores propios (`axiosInterceptor.js`) que dependen de `error.response`. Requiere pruebas de regresión del POS completo. |
| `swiper` (npm) | crítica (prototype pollution) | 5.4 → 14.2 (major) | Solo se usa en el carrusel de categorías de la vitrina pública (`frontend/components/Category.js`). Nueve majors de diferencia; `react-id-swiper` está abandonado. |

---

## 🟡 Medio — ARREGLADO

### La política de contraseñas se podía esquivar por el reset

El alta exige `Password::min(8)->letters()->numbers()`
(`CreateOrganizationRequest`), pero `AuthController::resetPassword()` validaba
`'required|min:6|confirmed'`. Cualquiera podía bajar su contraseña a 6
caracteres pasando por "olvidé mi contraseña" — y eso abarata justamente el
ataque de fuerza bruta contra el que protege el throttle del login.

Ahora ambos controladores de reset (web y `M1` móvil) usan la misma regla que el
alta. Ojo al detalle en `AuthController`: `Password` ahí es la **fachada** del
broker de recuperación, así que la regla se importa como `PasswordRule`
(`Illuminate\Validation\Rules\Password`).

### Enumeración de usuarios en la recuperación de contraseña

`sendPasswordResetLinkEmail()` respondía *"We can't find a user with that e-mail
address"* cuando el correo no existía, convirtiendo el endpoint en un oráculo
para saber qué correos están registrados en la plataforma.

Ahora la respuesta es idéntica exista o no el correo, en la API web y en la
móvil. Tampoco se distingue el caso de throttling del broker, por el mismo
motivo; el rate limit de la ruta ya frena el abuso.

### `/reset-password` sin límite de intentos

Era la única ruta de autenticación sin `throttle`, lo que permitía probar tokens
de recuperación sin freno. Ahora tiene `throttle:5,1`, igual que sus vecinas,
tanto en `routes/api.php` como en `routes/m1.php`.

---

## 🟢 Menores — ARREGLADOS

- **Contraseña de MySQL en la línea de comandos.** `BackupController` la pasaba
  como `--password=X`, visible con `ps aux` para cualquier usuario de la
  máquina mientras el dump corre. Ahora va por `MYSQL_PWD`, y la variable se
  restaura en un `finally`.
- **Tokens vencidos acumulándose.** Se agregó
  `sanctum:prune-expired --hours=24` al scheduler diario. Los tokens vencen a
  las 2 horas pero Laravel no borra las filas solo: `personal_access_tokens`
  crecía sin techo con credenciales muertas.
- **Enumeración de usuarios por tiempo de respuesta en el login.** El mensaje
  ya era idéntico para usuario inexistente y contraseña incorrecta, pero el
  tiempo no: sin usuario nunca se llegaba a `Hash::check()` y la respuesta
  volvía notablemente antes, lo que permite distinguir correos registrados
  midiendo la latencia. Ahora esa rama gasta el mismo bcrypt contra
  `AuthController::DUMMY_HASH`, un hash de descarte que no corresponde a
  ninguna contraseña utilizable.

---

## Endurecimiento adicional completado

### 2FA obligatorio para el super admin

El superadministrador administra TOTP desde su menú de perfil usando Google
Authenticator, Authy o cualquier aplicación compatible. La clave se cifra con
`APP_KEY`, se entregan ocho códigos de recuperación de un solo uso y se impide
reutilizar un código temporal. Antes de completar el enrolamiento, el login
emite un token limitado que solo puede acceder a las rutas de configuración de
2FA. El layout resuelve este estado antes de montar cualquier módulo y muestra
el modal obligatorio, evitando redirecciones o pantallas en blanco. El resto
del panel exige tanto 2FA activo como una capacidad administrativa completa.

### Bloqueo temporal de cuenta

Además del throttle por IP, diez contraseñas incorrectas bloquean la cuenta por
15 minutos. El conteo se actualiza con bloqueo de fila para resistir intentos
distribuidos y la respuesta pública sigue siendo genérica para no revelar qué
correos existen.

## Pendientes reales

### 1. `CORS_ALLOWED_ORIGINS` en producción

`config/cors.php` cae a `'*'` si la variable no está definida. Mitigado porque
`supports_credentials` es `false`, pero hay que fijarlo al desplegar:

```
CORS_ALLOWED_ORIGINS=https://tudominio.com
```

### 2. Revisar `laravelcollective/html`

`composer update` avisó que está abandonado. No tiene advisories hoy, pero un
paquete sin mantenimiento en el camino de renderizado es deuda a plazo.

---

## Verificación

`php artisan test` → **146 pasaron**, con la suite completa después de todos los
cambios de aislamiento, autenticación y del `composer update`.

`tests/Feature/SecurityHardeningTest.php` cubre las regresiones:

- los archivos privados del SRI viven fuera del docroot;
- el disco `local` sigue siendo el público (para que nadie "arregle" el
  problema moviendo el disco equivocado y rompa las imágenes de productos);
- el reset de contraseña no puede debilitar la política del alta;
- la recuperación de contraseña no revela si el correo existe.

## Ojo al hacer commit

`vendor/` está versionado en este repo, así que el `composer update` movió
**13 159 archivos** además de `composer.lock`. El commit va a ser enorme y el
diff, ilegible.

Conviene separarlo: un commit solo para `composer.lock` + `vendor/` con un
mensaje que diga qué se actualizó y por qué, y otro con los cambios de
aplicación. Si en algún momento se decide sacar `vendor/` del repo, hay que
cambiar antes el proceso de despliegue para que corra `composer install
--no-dev --optimize-autoloader` en el servidor.

Lo mismo con `package-lock.json` y el bundle recompilado (`public/js/app.js`).

## Antes de desplegar

Además de lo del `docs/saas/` habitual:

1. `CORS_ALLOWED_ORIGINS` con el dominio real.
2. `APP_DEBUG=false` (ya viene así en `.env.example`).
3. `APP_KEY` respaldada fuera del servidor — cifra las claves de los
   certificados con `Crypt::encryptString`; si se pierde, todos los clientes
   pierden la facturación electrónica.
4. `storage/app/private` fuera del docroot y dentro del respaldo diario.
