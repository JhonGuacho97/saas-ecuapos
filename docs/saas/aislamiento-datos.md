# Aislamiento de datos por tienda

Estado: implementado el 2026-09-08 sobre `codex/ecuapos-saas`, encima de
`68b45c72`. Este documento es el traspaso: qué cambió, por qué, y qué queda
pendiente.

## Decisión de arquitectura: una sola base de datos

EcuaPos usa **una base compartida** con `store_id` / `organization_id` en las
filas, no una base por cliente. Las razones, para no volver a discutirlo:

- El alta es self-service con prueba gratuita (`SAAS_SELF_REGISTRATION_ENABLED`).
  Con base por cliente, cada registro de curioso crea una base de datos que
  después hay que aprovisionar, migrar y limpiar.
- Cada despliegue tendría que migrar N bases. Si la migración falla a la mitad,
  el parque queda partido entre dos esquemas.
- El volumen de una PyME ecuatoriana no lo justifica: unos miles de ventas al
  mes por local.
- Los secuenciales del SRI ya están aislados por índice único
  (`electronic_invoices(store_id, ambiente, estab, pto_emi, tipo_comprobante, secuencial)`),
  que funciona igual en base compartida.

Se reconsidera **solo** si aparece uno de estos tres casos: un cliente exige
aislamiento físico por contrato, un tenant pesa más que todo el resto junto, o
entra una obligación de residencia de datos.

Lo que sí duele de la base compartida, y hay que tener presente:

- **Restaurar a un solo cliente** obliga a levantar el respaldo completo en una
  base temporal y copiar filas. Mitigación: *soft deletes* en lo que duele y un
  export lógico por organización.
- **Vecino ruidoso**: un tenant con catálogo enorme enlentece los reportes de
  todos. Se resuelve con índices, no separando bases.

## El problema que se resolvió

Hasta `68b45c72` el aislamiento era **manual**: cada consulta tenía que acordarse
de llamar a `scopeQueryToCurrentStore()`, `authorizeStoreOwnership()` o
`authorizeStoreModelId()`. Eran ~193 llamadas repartidas en 68 archivos.

Eso funciona mientras nadie olvide una. El día que se agregue un endpoint sin
acordarse, el bug **no explota**: devuelve datos de otro negocio.

La corrección invierte la carga con un *global scope* de Eloquent: el modelo
filtra siempre por la tienda activa, y cruzar tiendas hay que pedirlo explícito.
Olvidarse pasó a ser seguro; lo peligroso es ahora lo que se escribe a propósito,
que es justo lo revisable en un diff.

**Los helpers manuales siguen vivos y hay que seguir usándolos.** El scope es una
red de seguridad, no un reemplazo: `scopeQueryToCurrentStore()` además filtra
bodegas **activas**, semántica que el scope no cubre (ver
`WarehouseActivationTest`).

## Qué se agregó

### `app/Models/Concerns/BelongsToStore.php`

Para modelos con columna `store_id` propia.

- *Global scope* `store`: agrega `where <tabla>.store_id = currentStoreId()`.
  Calificado con el nombre de tabla para no romper consultas con join.
- Hook `creating`: rellena `store_id` con la tienda activa si viene vacío. Un
  `store_id` explícito **gana** sobre el autorrelleno (seeders y comandos
  necesitan escribir en otra tienda).
- `Model::acrossStores()` para saltarse el scope a propósito.

### `app/Models/Concerns/BelongsToStoreThroughWarehouse.php`

Para las tablas transaccionales, que solo tenían `warehouse_id`.

Igual que el anterior, salvo el `creating`: deriva la tienda de
`currentStoreId()` y, si no hay request (jobs del SRI, sync offline), la busca
por la bodega. Si no resuelve ninguna, **lanza `RuntimeException`** en vez de
guardar. Una fila con `store_id` null quedaría escondida por el scope sin que
nadie se entere; mejor reventar en el insert.

### `database/migrations/2026_09_08_100000_denormalize_store_id_on_transactional_tables.php`

Baja `store_id` a `sales`, `purchases`, `sales_return`, `purchases_return`,
`quotations`, `expenses`, `adjustments`, `holds`, `credit_notes` y `pos_register`:
columna nullable + FK a `stores` + backfill desde `warehouses` + índice compuesto
`(store_id, date)` — `(store_id, created_at)` en `pos_register`.

Antes, esas tablas solo tenían el índice de la FK `warehouse_id`, así que un
reporte "ventas de esta tienda este mes" no tenía por dónde agarrarse.

`down()` borra la FK **antes** del índice: MySQL se apoya en el índice compuesto
para sostener la constraint y rechaza el `DROP INDEX` mientras exista. Rollback y
re-migración verificados.

### `tests/Feature/StoreScopeTest.php`

Ocho casos que fijan el contrato de ambos traits. Es la red que permite aplicar
el trait a más modelos sin volver a razonar qué hace exactamente.

## Modelos alcanzados

**Con `BelongsToStore`** (22): `Brand`, `CashMovement`, `CashRegister`,
`CatalogOrder`, `CatalogSetting`, `CouponCode`, `Customer`, `CustomerAccount`,
`ElectronicInvoice`, `ExpenseCategory`, `InventoryCount`, `MainProduct`,
`PresentationFamily`, `PresentationType`, `Product`, `ProductCategory`,
`SriSequence`, `SriSequenceAdjustment`, `Supplier`, `Variation`, `VariationType`,
`Warehouse`.

**Con `BelongsToStoreThroughWarehouse`** (8): `Sale`, `Purchase`, `SaleReturn`,
`PurchaseReturn`, `Quotation`, `Expense`, `Adjustment`, `Hold`.

### Excluidos a propósito — no aplicar el trait

| Modelo | Motivo |
|---|---|
| `Setting` | Filas de fallback de sistema con `store_id` NULL. Un `where store_id = X` las deja fuera y rompe `getSettingValue()` / `getLogoUrl()`. |
| `MailTemplate` | Mismo patrón: `effectiveForStore()` busca la fila global (NULL) y la del tenant, y se queda con la del tenant. Además `MailTemplateAPIController` clona las globales a la tienda en el primer acceso — con el scope encima no encontraba ninguna que clonar (fue el único test que se rompió al aplicarlo). |
| `SmsTemplate` | Igual que `MailTemplate`. |
| `SmsSetting` | Igual, vía `effectiveValue()`. |
| `Role` | Usa el sistema de *teams* de Spatie, que ya trae su propio filtrado por `store_id`. |
| `CreditNote`, `POSRegister` | Tienen `warehouse_id` **nullable**, así que el backfill puede dejar `store_id` en NULL y el scope escondería esas filas. Recibieron columna, FK e índice, pero **no** el scope. |

## Cómo aplicar el trait a un modelo nuevo

1. Confirmar que la tabla tiene `store_id` **NOT NULL** o que siempre se puede
   derivar de una bodega no nula.
2. Confirmar que el modelo **no** usa el patrón de fila global con `store_id`
   NULL (buscar `whereNull('store_id')` y `orderByDesc('store_id')`).
3. Agregar `use App\Models\Concerns\BelongsToStore;` y sumar el trait al `use`
   del cuerpo de la clase.
4. `php artisan test` — la suite completa, no solo el módulo tocado.

## Verificación

`php artisan test` → **136 pasaron** (128 antes de este trabajo + 8 nuevos).
Incluye `CriticalTenantIsolationTest` y `OrganizationIsolationTest` sin tocar.

## Pendientes cerrados el 2026-09-08

1. `2026_09_08_120000_complete_transactional_store_isolation` completa las
   notas de crédito desde su venta, recupera las cajas desde bodega/caja/
   movimientos/pagos/devoluciones y convierte a `NOT NULL` las tablas cuya
   pertenencia es determinista. `pos_register.store_id` permanece nullable solo
   para sesiones históricas huérfanas.
2. `CreditNote` deriva y valida la tienda de su venta. `POSRegister` aplica el
   scope por su bodega para todas las sesiones nuevas y las históricas
   recuperables.
3. `BelongsToStoreThroughParent` protege consultas e inserciones directas de
   `SaleItem`, `PurchaseItem`, `QuotationItem`, `HoldItem`, `AdjustmentItem`,
   `CreditNoteItem`, `SalesPayment`, `SaleReturnItem` y `PurchaseReturnItem`.
4. `ManageStock` usa el mismo aislamiento a través de `warehouse`, sin duplicar
   `store_id` en la tabla más grande.

## Regla que se conserva

5. **No se tocó ningún helper manual, y conviene dejarlo así.** Los códigos de
   respuesta no cambiaron: `ProductAPIController::show()` hace
   `$this->productRepository->find($id)`, que con el scope activo devuelve
   `null` para una fila ajena, y `authorizeStoreOwnership(null)` ya contempla
   ese caso y sigue respondiendo 403. El scope y los chequeos manuales se
   refuerzan entre sí; quitar los segundos solo agregaría riesgo.

La suite ampliada agrega tres casos para modelos hijo y stock directo. El total
actual es **160 pruebas**.

## Visibilidad deliberada del superadministrador

El superadministrador puede consultar organizaciones y sus usuarios para
soporte, pero el listado de `/api/super-admin/users` no equivale a un volcado de
la tabla `users`: excluye cuentas con `is_super_admin = true` y filas sin una
membresía en `organization_user`. Los endpoints individuales aplican la misma
regla antes de devolver información o restablecer credenciales. Esta separación
evita mezclar las identidades internas de la plataforma con las cuentas de los
clientes SaaS.
