<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateStoreRequest;
use App\Http\Requests\UpdateStoreRequest;
use App\Http\Resources\StoreCollection;
use App\Http\Resources\StoreResource;
use App\Models\Role;
use App\Models\Store;
use App\Repositories\StoreRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CRUD de tiendas -- pantalla "Stores" del admin (ver captura de
 * referencia). Distinto de misTiendas(): esto administra el catálogo
 * de tiendas, no solo lista las accesibles para el selector del
 * Header. Gateado por el permiso manage_stores.
 */
class StoreAPIController extends AppBaseController
{
    private StoreRepository $storeRepository;

    public function __construct(StoreRepository $storeRepository)
    {
        $this->storeRepository = $storeRepository;
    }

    /**
     * Tiendas a las que el usuario autenticado tiene acceso
     * (user_store). El frontend usa esto para decidir si mostrar el
     * selector (2+ tiendas) o resolver en silencio (0 o 1 tienda).
     */
    public function misTiendas(): JsonResponse
    {
        // Incluye tiendas desactivadas a propósito -- el selector del
        // Header las muestra pero bloqueadas (ver Header.js), en vez de
        // hacerlas desaparecer sin explicación. ResolveActiveStore
        // igual rechaza cualquier intento de resolverlas por
        // X-Store-Id, sin importar lo que mande el frontend.
        $stores = Auth::user()->stores()
            ->when(currentOrganizationId(), fn ($query, $organizationId) => $query->where('stores.organization_id', $organizationId))
            ->orderBy('name')
            ->get(['stores.id', 'stores.organization_id', 'stores.name', 'stores.slug', 'stores.is_active', 'stores.is_default']);

        return $this->sendResponse($stores, 'Tiendas retrieved successfully');
    }

    public function index(Request $request): StoreCollection
    {
        $perPage = getPageSize($request);
        $stores = $this->storeRepository
            ->where('organization_id', $this->requireCurrentOrganizationId())
            ->withCount('users')
            ->paginate($perPage);
        StoreResource::usingWithCollection();

        return new StoreCollection($stores);
    }

    public function store(CreateStoreRequest $request): StoreResource
    {
        $input = $request->all();
        $store = $this->storeRepository->storeStore($input);
        $this->grantCreatorAccess($store);

        return new StoreResource($store);
    }

    /**
     * Sin esto, la tienda queda huérfana: quien la creó no la vería en
     * su selector (user_store) ni podría operarla ni asignarle otros
     * usuarios (resolveGrantableStoreIds() en UserRepository exige que
     * el admin que asigna YA tenga acceso a la tienda). Se le clona un
     * rol de su tienda actual -- antes esto buscaba específicamente un
     * rol llamado 'admin', así que un creador con un rol admin-
     * equivalente de otro nombre (ej. SUPER_ADMIN) no encontraba nada y
     * la tienda nueva quedaba sin rol clonado. Ahora se clona el propio
     * rol del creador (roles() ya viene filtrado a la tienda activa por
     * el modo teams de Spatie), priorizando uno sin restricciones si
     * tiene más de uno -- ver Role::unrestrictedRoleIds().
     */
    private function grantCreatorAccess(Store $store): void
    {
        $creator = Auth::user();
        $creator->stores()->syncWithoutDetaching([$store->id]);

        $creatorRoles = $creator->roles;
        $unrestrictedRoleIds = Role::unrestrictedRoleIds();
        $sourceRole = $creatorRoles->first(fn (Role $role) => in_array($role->id, $unrestrictedRoleIds, true))
            ?? $creatorRoles->first();

        if (!$sourceRole) {
            return;
        }

        $originalTeamId = getPermissionsTeamId();
        try {
            setPermissionsTeamId($store->id);
            $newRole = Role::create([
                'name' => $sourceRole->name,
                'display_name' => $sourceRole->display_name,
                'guard_name' => $sourceRole->guard_name,
            ]);
            $newRole->syncPermissions($sourceRole->permissions);
            $creator->assignRole($newRole);
        } finally {
            setPermissionsTeamId($originalTeamId);
        }
    }

    public function show(Store $store): StoreResource
    {
        $this->authorizeOrganizationStore($store);

        return new StoreResource($store);
    }

    public function update(UpdateStoreRequest $request, Store $store): StoreResource
    {
        $this->authorizeOrganizationStore($store);
        $input = $request->all();

        // "Predeterminada" es un singleton -- solo una tienda puede
        // serlo a la vez (es la que se auto-selecciona al loguearse con
        // 2+ tiendas, ver storeAction.js::fetchMyStores()). Se desmarca
        // a las demás en la misma operación en vez de depender de un
        // constraint de BD, mismo criterio que el resto de este módulo.
        if (!empty($input['is_default'])) {
            Store::where('organization_id', $store->organization_id)
                ->where('id', '!=', $store->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $updated = $this->storeRepository->update($input, $store->id);

        return new StoreResource($updated);
    }

    public function destroy(Store $store): JsonResponse
    {
        $this->authorizeOrganizationStore($store);
        // store_id en warehouses/products/etc es onDelete('restrict') --
        // MySQL ya lo bloquea, esto solo lo convierte en un mensaje
        // legible en vez de un error 500 crudo.
        try {
            $wasDefault = $store->is_default;
            $store->delete();
        } catch (QueryException $e) {
            return $this->sendError('No se puede eliminar: esta tienda todavía tiene datos asociados (almacenes, productos, clientes, etc.).');
        }

        // Sin una tienda default, el auto-select del login queda sin
        // nada que usar hasta que alguien marque una a mano -- se
        // promueve la más antigua de las que quedan para no dejar ese
        // hueco.
        if ($wasDefault) {
            $nextDefaultId = Store::where('organization_id', $store->organization_id)->orderBy('id')->value('id');
            if ($nextDefaultId !== null) {
                Store::whereKey($nextDefaultId)->update(['is_default' => true]);
            }
        }

        return $this->sendSuccess('Store deleted successfully');
    }

    private function authorizeOrganizationStore(Store $store): void
    {
        if ((int) $store->organization_id !== $this->requireCurrentOrganizationId()) {
            throw new AccessDeniedHttpException('No tiene acceso a esta tienda.');
        }
    }
}
