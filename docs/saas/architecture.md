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

El aislamiento entre tiendas, la decisión de usar una sola base de datos y los
traits que la sostienen están documentados aparte en
[`aislamiento-datos.md`](aislamiento-datos.md). La auditoría de seguridad, el
endurecimiento aplicado y lo que queda pendiente, en
[`seguridad.md`](seguridad.md).

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
3. **Planes y límites:** catálogo de planes, límites de
   usuarios/tiendas/almacenes/documentos y periodo de prueba. Implementada;
   el bloqueo selectivo de módulos por `features` sigue pendiente.
4. **Suscripción y cobros:** ciclo de suscripción, renovaciones, comprobantes,
   gracia, suspensión, cancelación y reactivación. Implementada para pagos
   manuales y preparada para pasarelas automáticas.
5. **Panel de plataforma:** organizaciones, usuarios, planes, suscripciones,
   pagos, landing page, respaldo y seguridad del superadministrador.
   Implementada.
6. **Operación SaaS:** tareas programadas, backups, auditoría y seguridad
   base implementadas. Observabilidad, colas permanentes y despliegue sin
   interrupciones continúan como trabajo operativo.

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

## Plan de prueba y límites

Cada organización creada desde el onboarding recibe automáticamente el plan
`trial` con estas condiciones:

- 14 días de prueba;
- acceso a todos los módulos (`features: ["*"]`);
- máximo 1 usuario, 1 tienda y 1 almacén;
- máximo 10 documentos electrónicos únicos.

Las instalaciones que ya existían al introducir esta fase reciben el plan
interno `legacy`, sin límites, para que una migración no bloquee su operación.

Los límites de usuarios, tiendas y almacenes se comprueban dentro de la misma
transacción que crea el recurso. El cupo de documentos electrónicos usa una
reserva idempotente por documento de origen: un doble clic o un reintento del
mismo comprobante no descuenta cupo dos veces. Si una venta solicita factura
electrónica, venta, inventario y reserva fiscal se confirman o revierten como
una sola operación.

Al terminar la prueba, la organización entra en modo de solo lectura: puede
consultar sus datos, pero las peticiones que creen o modifiquen información
responden con HTTP 402 y una causa estructurada. Desde el portal de suscripción
puede elegir un plan, cargar un comprobante y recuperar la operación cuando el
superadministrador aprueba el pago.
