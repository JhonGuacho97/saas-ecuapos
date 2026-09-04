# Arquitectura SaaS de EcuaPos

## Jerarquía de datos

```text
EcuaPos (plataforma)
└── Organización (cliente, suscripción y límite de seguridad)
    ├── Usuarios de la organización
    └── Tienda (operación, permisos y configuración fiscal)
        └── Almacén (inventario y sucursal)
```

La organización es el tenant SaaS. La tienda sigue siendo el team usado por
Spatie Permission y conserva su configuración de SRI, catálogo, inventario y
caja. Este diseño permite vender un plan por empresa y ofrecer varias tiendas
sin mezclar sus operaciones.

## Reglas que no deben romperse

1. Ningún `organization_id`, `store_id` o `warehouse_id` recibido del cliente
   se usa sin comprobar la membresía del usuario.
2. El contexto de organización se deriva preferentemente de la tienda activa.
3. Los permisos operativos continúan siendo por tienda; los roles de
   organización (`OWNER`, `ADMIN`, `MEMBER`) administran la cuenta SaaS.
4. Planes y límites son *entitlements*, no permisos de empleado.
5. Las consultas administrativas de tiendas siempre se limitan a la
   organización activa.
6. Los procesos en cola deberán transportar explícitamente la organización y
   la tienda; nunca deben depender del último contexto HTTP.

## Compatibilidad con la instalación heredada

La migración inicial trata toda la base instalada como un solo cliente:

- crea una organización usando `settings.company_name`;
- asigna todas las tiendas existentes a esa organización;
- vincula a los usuarios presentes en `user_store`;
- elige un administrador existente como `OWNER`;
- no modifica ventas, compras, inventario, caja, clientes ni SRI.

## Fases

1. **Fundación tenant:** organización, membresías, resolución segura de
   contexto y aislamiento de tiendas. Implementada.
2. **Onboarding:** registro del propietario, creación atómica de organización,
   tienda, almacén, configuración base y usuario administrador. Implementada.
3. **Planes y límites:** catálogo de planes, funcionalidades habilitadas,
   límites de usuarios/tiendas/almacenes y periodo de prueba.
4. **Suscripción y cobros:** ciclo de suscripción, renovaciones, comprobantes,
   gracia, suspensión y reactivación.
5. **Panel de plataforma:** soporte interno, organizaciones, suscripciones,
   salud operativa y acceso asistido auditable.
6. **Operación SaaS:** colas, tareas programadas, backups, observabilidad,
   auditoría y estrategia de despliegue sin interrupciones.

## Onboarding implementado

El alta pública está disponible en `/crear-cuenta` y usa
`POST /api/onboarding/register`. El proceso se ejecuta dentro de una única
transacción: si falla cualquier paso, no queda una empresa creada a medias.

Cada registro nuevo prepara:

- organización y membresía `OWNER`;
- tienda principal y almacén principal;
- propietario activo, verificado y con idioma español;
- rol administrador aislado por tienda con los permisos disponibles;
- consumidor final, caja principal y catálogo inicialmente desactivado;
- configuración base de Ecuador, dólar estadounidense y marca EcuaPos.

Los datos fiscales sensibles no se heredan de otra organización. El RUC,
ambiente SRI, firma electrónica, claves, secuenciales y datos legales deben ser
configurados expresamente por el propietario después del primer acceso.

El registro puede deshabilitarse sin cambiar código mediante
`SAAS_SELF_REGISTRATION_ENABLED=false`.
