# 🧾 EcuaPos (Laravel + React)

Sistema de Punto de Venta (POS) desarrollado con **Laravel (backend)** y **React (frontend)**.

---

## 🚀 Requisitos previos

Antes de ejecutar el proyecto, asegúrate de tener instalado:

- PHP >= 8.x
- Laravel 10
- Composer  
- Node.js y npm  
- MySQL o MariaDB  
- Servidor local (XAMPP, Laragon, etc.)

---

## ⚙️ Instalación

Sigue estos pasos para levantar el proyecto en tu entorno local:

### 1. Instalar dependencias de Laravel
composer install

### 2. Configurar variables de entorno
Copia el archivo .env.example y renómbralo a .env:
Configura la conexión a la base de datos en el archivo .env:

### 3. Preparar la base de datos

**Solo para entorno LOCAL de desarrollo.** Dirígete a la carpeta `/database`
e importa el archivo `pos.sql`.

⚠️ **No importes `pos.sql` en una instalación SaaS nueva.** Es un dump de
datos de demostración/desarrollo e incluye datos de una instalación anterior.
En una base vacía usa:

```bash
php artisan migrate --force
php artisan saas:install
```

El instalador solicita las credenciales de forma interactiva y crea un único
superadministrador global, sin organización, tienda, bodega ni datos de
negocio. Las organizaciones se crean después mediante el registro público.

No uses `php artisan migrate --seed` para este escenario: el seeder general se
conserva para instalaciones POS heredadas y entornos de desarrollo.

### 4. Instalar dependencias de Node.js
npm install

### 5. Levantar el proyecto
npm run dev

---

## 🚀 Despliegue en producción (checklist mínimo)

- `php artisan key:generate` en el `.env` real de producción — nunca
  reutilizar el `APP_KEY` de `.env.example`.
- En un SaaS nuevo: `php artisan migrate --force` y después
  `php artisan saas:install` (no importar `pos.sql` ni ejecutar el seeder
  general).
- `QUEUE_CONNECTION=database` (no `sync`) + cron para `php artisan queue:work`,
  necesario para que la facturación electrónica SRI funcione de forma
  asíncrona y no bloquee el request del usuario.
- Configurar explícitamente el ambiente del SRI (pruebas vs. producción)
  antes de emitir el primer comprobante real.
- `composer install --no-dev --optimize-autoloader`, `php artisan config:cache`,
  `route:cache`.
- Verificar permisos de escritura en `storage/` y `bootstrap/cache/`.
- Restringir `config/cors.php` al dominio real de producción.

### Tecnologías usadas
Laravel
React
MySQL
Node.js


### DESARROLLADOR
EcuaPosSoft
LICENCIA PATENTADA
