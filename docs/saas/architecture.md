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
   tienda, almacén, configuración base y usuario administrador.
3. **Planes y límites:** catálogo de planes, funcionalidades habilitadas,
   límites de usuarios/tiendas/almacenes y periodo de prueba.
4. **Suscripción y cobros:** ciclo de suscripción, renovaciones, comprobantes,
   gracia, suspensión y reactivación.
5. **Panel de plataforma:** soporte interno, organizaciones, suscripciones,
   salud operativa y acceso asistido auditable.
6. **Operación SaaS:** colas, tareas programadas, backups, observabilidad,
   auditoría y estrategia de despliegue sin interrupciones.

