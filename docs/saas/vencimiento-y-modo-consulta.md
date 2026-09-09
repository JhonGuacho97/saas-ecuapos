# Vencimiento de la suscripción y modo consulta

Implementado el 2026-09-08 sobre `6ec92a41`. Antes existía una incoherencia:
el backend, los tests y los mensajes decían "modo consulta", pero la interfaz
tapaba la app entera con el muro de pago apenas vencía la prueba. Ahora el modo
consulta funciona de verdad.

## Los tres modos de acceso

El portal (`GET /api/subscription-portal`) devuelve `access_mode`:

| Modo | Cuándo | Qué ve el usuario |
|---|---|---|
| `full` | Organización activa y suscripción vigente (`ACTIVE`, `TRIALING`, o `PAST_DUE` dentro de la gracia) | La app normal. |
| `read_only` | Organización **activa** y suscripción vencida, cancelada o inexistente | La app completa con una barra de modo consulta. Puede navegar, consultar y exportar; no puede escribir. |
| `blocked` | Organización **desactivada** por la plataforma | El muro de pago a pantalla completa. |

La distinción de fondo: vencer no es lo mismo que estar suspendido. En el
primer caso los datos siguen siendo del cliente y ya se podían leer por API;
lo que faltaba era dejarlo entrar a verlos.

`can_access` se conserva con su significado anterior (`true` solo en `full`)
para no romper a los consumidores existentes.

## Cuándo se corta, exactamente

`OnboardingService` fija `trial_ends_at = now()->addDays($plan->trial_days)`
(hoy 14). **La prueba no tiene días de gracia**: aunque el plan `trial` trae
`grace_days: 3`, `EntitlementService::isExpired()` solo aplica gracia a
períodos pagados —`ACTIVE` suma `grace_days` a `current_period_ends_at`, y
`PAST_DUE` usa `grace_ends_at`. La rama `TRIALING` compara contra
`trial_ends_at` a secas.

**No hace falta esperar al cron.** `isExpired()` se evalúa en cada petición, así
que el corte es inmediato. El `saas:reconcile-subscriptions` horario solo
persiste `TRIALING → EXPIRED` en la base.

## Qué sigue funcionando en modo consulta

`EnsureActiveSubscription` solo frena métodos no seguros:

```php
if (! $request->isMethodSafe()) {
    $this->entitlements->assertOrganizationWritable(requireCurrentOrganizationId());
}
```

- **GET/HEAD/OPTIONS pasan.** Listados, detalles, reportes y dashboards.
- **Las exportaciones funcionan**, porque todas son GET (`products-export-excel`,
  `sales-report-excel`, los `*-pdf-download`). Eso es lo que hace verdadera la
  promesa del mensaje de error.
- **POST/PUT/PATCH/DELETE devuelven 402** con `restriction: 'trial_expired'`.
- **El portal de suscripción queda fuera del guardia** (su grupo de rutas no
  lleva `subscription.active`), así que un cliente vencido sí puede enviar su
  comprobante para renovar. Sin esa excepción no habría forma de salir del
  vencimiento.
- **El POS offline no sobrevive**: tanto la fecha entregada al navegador como
  la credencial Sanctum se topan contra el final efectivo del acceso. Además,
  los endpoints de sincronización vuelven a validar `subscription.active` en
  el servidor; un token emitido antes del vencimiento no puede escribir luego.
- **Perfil, contraseña, idioma y cierre de sesión siguen disponibles.** Son
  acciones de la cuenta, no operaciones del negocio. El cierre local también
  elimina las credenciales aunque la llamada al servidor falle.
- **No se borra nada.** No existe ningún job de limpieza en el módulo SaaS.

## Qué se agregó

### `resources/pos/src/components/subscription/ReadOnlyBanner.js`

Barra fija con el motivo y un botón "Renovar ahora" que lleva a
`/app/subscription` (o, si el usuario no administra la cuenta, el aviso de que
se lo pida a un administrador).

Va **abajo y no arriba** a propósito: el encabezado y la barra lateral del POS
son fijos y una franja superior se les monta encima. Se puede minimizar a una
pastilla, pero no cerrar: mientras la suscripción esté vencida el usuario tiene
que poder ver por qué no le deja guardar.

### `App.js`

- Rama nueva para `access_mode === 'read_only'`: renderiza `AdminApp` más la
  barra, en vez del muro.
- El arranque del workspace (`fetchMyStores` + `fetchConfig` +
  `fetchFrontSetting`, todos GET) ahora también corre en modo consulta; sin eso
  `AdminApp` se quedaba en el cargador.
- Ese arranque solo corre **al entrar** al modo, protegido por `accessModeRef`:
  cada escritura rechazada dispara `saas:access-blocked`, y sin la guarda un
  usuario probando botones recargaba el workspace entero en cada intento.
- El evento `saas:access-blocked` ahora además muestra un toast con el mensaje
  del servidor. El interceptor no puede despachar al store (no se exporta desde
  `index.js`), por eso el aviso se arma acá. Sin esto el usuario aprieta
  "Guardar", no pasa nada visible y parece que la app está rota.

### Mensajes corregidos

`EntitlementService::assertWritable()` armaba el 402 con **"14 días" fijo** y
hablaba de "prueba" incluso cuando lo vencido era un plan pagado. Ahora usa
`plan->trial_days` real y distingue prueba de suscripción. Cubierto por
`test_the_expiry_message_uses_the_real_trial_length`.

El muro de pago también decía "TU PERÍODO DE PRUEBA FINALIZÓ" en su rama
genérica; pasó a "TU SUSCRIPCIÓN FINALIZÓ", que es cierto en los dos casos.

## Verificación

`php artisan test` → **156 pasaron**.

## Endurecimiento posterior a la auditoría

- La cancelación programada se considera vencida exactamente en
  `current_period_ends_at`, sin depender de que el cron ya haya persistido el
  cambio de estado.
- Una organización sin fila de suscripción puede crear de forma atómica una
  suscripción pendiente y enviar su comprobante de recuperación.
- Las suspensiones registran motivo (`BILLING`, `ADMINISTRATIVE` o `SECURITY`),
  fecha y nota. Solo `BILLING` permite comprar y reactivarse con un pago; un
  comprobante nunca levanta silenciosamente un bloqueo administrativo.
- El catálogo público deja de publicar su shell cuando la organización o la
  suscripción no permiten pedidos.
- Limpiar la caché global quedó reservado al superadministrador y usa POST.

- `test_an_expired_subscription_is_read_only_and_a_suspended_organization_is_blocked`
  (renombrado desde `..._receives_one_subscription_screen_...`, que ya no
  describía el comportamiento) cubre los modos `read_only` y `blocked`.
- `test_expired_trial_is_read_only` ahora además comprueba que
  `GET /api/products` y `GET /api/customers` respondan 200 con la prueba
  vencida. Si algún día el middleware pasa a bloquear también los GET, ese test
  lo caza.
- `test_the_expiry_message_uses_the_real_trial_length` fija el mensaje contra
  un plan de 21 días.
- `test_config_reports_whether_the_interface_can_still_write` comprueba que
  `can_write` pase de `true` a `false` al vencer la prueba. Es el contrato del
  que cuelgan todos los botones deshabilitados.

## Botones deshabilitados en modo consulta

Se hizo sin tocar los ~60 módulos, aprovechando que casi toda la escritura del
POS pasa por cuatro componentes compartidos.

### La fuente: `can_write`

`GET /api/config` devuelve `can_write`, que resuelve
`EntitlementService::organizationCanWrite()` — la misma decisión que toma
`EnsureActiveSubscription` en cada escritura, pero como respuesta en vez de
excepción. Así la interfaz y el servidor no pueden contradecirse.

El hook `useReadOnlyMode()` lo lee del store y compara contra **`false`
explícito**, no contra un valor falsy. Un snapshot offline guardado antes de que
existiera la clave no la trae, y ahí NO hay que asumir solo lectura o el POS
quedaría inutilizable sin conexión. Ante la duda, la interfaz deja actuar y el
servidor decide.

### Dónde se aplicó

| Componente | Qué cubre |
|---|---|
| `shared/action-buttons/TableButton.js` | El botón "Crear/Nuevo" de todos los listados. |
| `shared/action-buttons/ActionButton.js` | Editar, borrar y cambiar contraseña por fila. |
| `shared/action-buttons/ActionDropDownButton.js` | Editar, borrar, crear pago, crear venta, crear devolución y emitir factura. |
| `shared/table/ReactDataTable.js` | Importar productos. |
| `frontend/components/cart-product/PaymentButton.js` | Cobrar y retener en el POS. |

Lo que **sigue habilitado a propósito** porque es lectura: ver, descargar PDF,
ver ticket, descargar RIDE, ver pagos, exportar a Excel, y reiniciar el carrito
(que es local).

Como el hook vive en el componente hoja y usa `useSelector`, funciona aunque el
padre memoice su subárbol —caso de `ReactDataTable`, cuyo encabezado está en un
`useMemo([])`.

### Los atajos de teclado también

`Alt+S` (cobrar) y `Alt+H` (retener) no pasan por el botón, así que
deshabilitarlo no alcanzaba: el atajo abría el modal igual y el cajero recién se
enteraba con el 402 al confirmar. Ahora esas dos ramas de `handleKeyPress`
avisan con un toast y no abren nada.

### Acceso directo a formularios

`AdminApp` redirige al dashboard las rutas de creación, edición y configuración
cuando `can_write` es `false`. Así, escribir manualmente una URL como
`/app/products/create` tampoco permite llegar a un formulario operativo. Los
controles compartidos continúan siendo la primera barrera visual y el backend
conserva la decisión definitiva mediante `subscription.active`.
