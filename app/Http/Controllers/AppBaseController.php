<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Warehouse;
use App\Utils\ResponseUtil;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @SWG\Swagger(
 *   basePath="/api/v1",
 *
 *   @SWG\Info(
 *     title="Laravel Generator APIs",
 *     version="1.0.0",
 *   )
 * )
 * This class should be parent class for other API controllers
 * Class AppBaseController
 */
class AppBaseController extends Controller
{
    public function sendResponse($result, $message): JsonResponse
    {
        return Response::json(ResponseUtil::makeResponse($message, $result));
    }

    public function sendError($error, $code = 422): JsonResponse
    {
        return Response::json(ResponseUtil::makeError($error), $code);
    }

    public function sendSuccess($message): JsonResponse
    {
        return Response::json([
            'success' => true,
            'message' => $message,
        ], 200);
    }

    /**
     * Sucursal a la que un usuario no-admin está restringido, o null si
     * es admin (ve todas) o no tiene sucursal asignada (por decisión de
     * producto, en ese caso conserva el comportamiento actual: ve todo).
     */
    protected function restrictedWarehouseId(): ?int
    {
        $user = Auth::user();
        if (!$user || $user->isUnrestrictedAdmin()) {
            return null;
        }

        if (! $user->default_warehouse_id) {
            return null;
        }

        return Warehouse::whereKey($user->default_warehouse_id)->active()->exists()
            ? (int) $user->default_warehouse_id
            : -1;
    }

    /**
     * Resuelve una bodega activa de la tienda, respetando la restricción
     * del usuario. Configuración, selector POS y apertura usan esta regla.
     */
    protected function defaultWarehouseForCurrentStore(): ?Warehouse
    {
        $storeId = $this->currentStoreId();
        if (! $storeId) {
            return null;
        }

        $query = Warehouse::where('store_id', $storeId)->active();
        if (($restricted = $this->restrictedWarehouseId()) !== null) {
            $query->whereKey($restricted);
        }

        $preferredId = getSettingValue('default_warehouse');

        return ($preferredId ? (clone $query)->whereKey($preferredId)->first() : null)
            ?? $query->orderBy('id')->first();
    }

    /** Valida tanto la restricción del usuario como la pertenencia a la tienda. */
    protected function authorizeWarehouseAccess(?int $warehouseId): void
    {
        $restricted = $this->restrictedWarehouseId();
        if ($restricted !== null && $warehouseId !== $restricted) {
            throw new AccessDeniedHttpException('No tiene permiso para acceder a datos de esta sucursal.');
        }

        $storeId = $this->currentStoreId();
        if ($storeId !== null && $warehouseId !== null) {
            $belongs = Warehouse::whereKey($warehouseId)->where('store_id', $storeId)->active()->exists();
            if (! $belongs) {
                throw new AccessDeniedHttpException('La bodega no pertenece a la tienda activa o se encuentra desactivada.');
            }
        }
    }

    /**
     * Filtra $query para que solo entren filas cuyo warehouse pertenece
     * a la tienda activa -- usar junto con restrictedWarehouseId() (que
     * no restringe admins) en el index()/búsquedas de módulos con una
     * columna warehouse_id propia (Sale, Purchase, CreditNote). El
     * aislamiento entre tiendas aplica siempre, sin excepción de rol.
     */
    protected function scopeQueryToCurrentStore($query, string $warehouseColumn = 'warehouse_id')
    {
        if ($storeId = $this->currentStoreId()) {
            $query->whereIn($warehouseColumn, Warehouse::where('store_id', $storeId)->active()->pluck('id'));
        }

        return $query;
    }

    /**
     * Lanza 403 si el modelo dado (cualquiera con columna store_id
     * propia -- Product, ProductCategory, Brand, Customer, Supplier,
     * etc.) no pertenece a la tienda activa. Usar en show/update/destroy
     * de esos módulos: index() ya filtra la lista, pero eso NO evita que
     * alguien pida un registro de OTRA tienda directo por ID (IDOR) --
     * hallado durante las pruebas de aislamiento de la Fase 14. Sin
     * tienda activa resuelta, no se puede validar nada acá y se deja
     * pasar, mismo criterio que el resto de esta fase.
     */
    protected function authorizeStoreOwnership($model): void
    {
        $storeId = $this->currentStoreId();
        if ($storeId !== null && $model !== null && $model->store_id !== null && $model->store_id !== $storeId) {
            throw new AccessDeniedHttpException('No tiene permiso para acceder a este registro.');
        }
    }

    /**
     * Tienda activa ya resuelta y validada por el middleware
     * ResolveActiveStore (ver app/Http/Middleware/ResolveActiveStore.php)
     * -- null si el usuario tiene 0 o 2+ tiendas y el frontend no mandó
     * el header X-Store-Id (todavía no hay ningún endpoint que dependa
     * de esto; se usa recién a partir de la Fase 4).
     *
     * NUNCA leer request()->header('X-Store-Id') directo en un
     * controller -- ese valor sin validar es exactamente lo que este
     * helper (y el middleware detrás) evita que se use.
     */
    protected function currentStoreId(): ?int
    {
        return currentStoreId();
    }

    /**
     * Exige que haya una tienda activa resuelta -- para endpoints que no
     * pueden operar sin saber en qué tienda están (crear un producto,
     * por ejemplo). Lanza 422 con un mensaje claro en vez de dejar que
     * el código de más abajo falle con un store_id null confuso.
     */
    protected function requireCurrentStoreId(): int
    {
        return requireCurrentStoreId();
    }

    protected function currentOrganizationId(): ?int
    {
        return currentOrganizationId();
    }

    protected function requireCurrentOrganizationId(): int
    {
        return requireCurrentOrganizationId();
    }

    /**
     * SalesPayment no tiene warehouse_id propio (solo sale_id) --
     * scopeQueryToCurrentStore() no le sirve directo, así que se filtra
     * a través de la venta.
     */
    protected function scopeSalesPaymentsToCurrentStore($query)
    {
        if ($storeId = $this->currentStoreId()) {
            $query->whereHas('sale', function ($q) use ($storeId) {
                $this->scopeQueryToCurrentStore($q);
            });
        }

        return $query;
    }

    /**
     * Nombres de TODOS los permisos del usuario, con un criterio
     * pensado para el bootstrap de la app (login(), /api/config) donde
     * el frontend necesita algo no-vacío para decidir qué mostrar antes
     * de que el usuario haya elegido explícitamente una tienda:
     *
     * - Si ya hay tienda activa resuelta (ResolveActiveStore ya corrió
     *   para este request, o el llamador la fijó a mano -- ver
     *   AuthController::login(), que corre antes de que exista sesión),
     *   se respeta esa: son sus permisos reales en esa tienda.
     * - Si no hay ninguna resuelta pero el usuario tiene exactamente
     *   una tienda activa, se resuelve sola (mismo criterio que
     *   ResolveActiveStore).
     * - Con 2+ tiendas activas y ninguna resuelta todavía (el hueco
     *   real: el usuario aún no eligió cuál usar, pero el frontend
     *   necesita ARMAR EL MENÚ para poder mostrarle el selector), se
     *   arma la UNIÓN de sus permisos en cada una de sus tiendas. Sin
     *   esto, un usuario con 2+ tiendas queda con permisos SIEMPRE
     *   vacíos hasta que elige una -- pero no puede elegir una si la
     *   pantalla entera está en blanco por falta de permisos: un
     *   candado circular. La unión no afloja ningún control real: cada
     *   request de datos sigue resolviéndose fresco contra la tienda
     *   activa real vía ResolveActiveStore + el middleware
     *   `permission:...` de cada ruta.
     */
    protected function allPermissionNamesForUser(User $user): array
    {
        if ($this->currentStoreId()) {
            return $user->getAllPermissions()->pluck('name')->toArray();
        }

        $storeIds = $user->stores()->where('stores.is_active', true)->pluck('stores.id');

        if ($storeIds->count() === 1) {
            setPermissionsTeamId($storeIds->first());

            return $user->getAllPermissions()->pluck('name')->toArray();
        }

        if ($storeIds->count() > 1) {
            // unsetRelation() entre vuelta y vuelta: getAllPermissions()
            // carga roles/permissions con loadMissing(), que no vuelve a
            // consultar si la relación ya está en caché -- sin esto, la
            // segunda tienda del loop devolvería los permisos
            // (incorrectos) de la primera.
            $names = [];
            foreach ($storeIds as $storeId) {
                setPermissionsTeamId($storeId);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $names = array_merge($names, $user->getAllPermissions()->pluck('name')->toArray());
            }
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return array_values(array_unique($names));
        }

        return [];
    }
}
