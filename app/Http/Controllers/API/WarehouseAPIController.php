<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseCollection;
use App\Http\Resources\WarehouseResource;
use App\Models\ManageStock;
use App\Models\CatalogSetting;
use App\Models\POSRegister;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Repositories\WarehouseRepository;
use App\Services\SaaS\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Prettus\Validator\Exceptions\ValidatorException;

/**
 * Class WarehouseAPIController
 */
class WarehouseAPIController extends AppBaseController
{
    /**
     * @var WarehouseRepository
     */
    private $warehouseRepository;

    public function __construct(
        WarehouseRepository $warehouseRepository,
        private readonly EntitlementService $entitlements
    )
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    public function index(Request $request): WarehouseCollection
    {
        $perPage = getPageSize($request);
        $warehousesQuery = $this->warehouseRepository;
        if ($storeId = $this->currentStoreId()) {
            $warehousesQuery->where('store_id', $storeId);
        }
        $canManageWarehouses = $request->user()?->can('manage_warehouses') ?? false;
        if (! ($request->boolean('include_inactive') && $canManageWarehouses)) {
            $warehousesQuery->where('is_active', true);
        }
        $warehouses = $warehousesQuery->paginate($perPage);
        WarehouseResource::usingWithCollection();

        return new WarehouseCollection($warehouses);
    }

    /**
     * @throws ValidatorException
     */
    public function store(CreateWarehouseRequest $request): WarehouseResource
    {
        $input = $request->all();
        $input['store_id'] = $this->requireCurrentStoreId();
        $input['is_active'] = $input['is_active'] ?? true;
        $warehouse = $this->entitlements->withinResourceLimit(
            $this->requireCurrentOrganizationId(),
            EntitlementService::RESOURCE_WAREHOUSES,
            fn () => $this->warehouseRepository->create($input)
        );

        return new WarehouseResource($warehouse);
    }

    public function warehouseDetails($id)
    {
        $this->authorizeWarehouseAccess((int) $id);
        $warehouses = ManageStock::where('warehouse_id', $id)->with('product')->get();

        $products = [];

        foreach ($warehouses as $warehouse) {
            $products[] = $warehouse->prepareWarehouseAttributes();
        }

        return $this->sendResponse($products, 'Products Retrived Successfully');
    }

    public function show($id): WarehouseResource
    {
        $warehouse = $this->warehouseRepository->find($id);
        $this->authorizeStoreOwnership($warehouse);

        return new WarehouseResource($warehouse);
    }

    /**
     * @throws ValidatorException
     */
    public function update(UpdateWarehouseRequest $request, $id): WarehouseResource
    {
        $existing = $this->warehouseRepository->find($id);
        $this->authorizeStoreOwnership($existing);
        $input = $request->all();

        $warehouse = DB::transaction(function () use ($existing, $input, $id) {
            $isBeingDisabled = array_key_exists('is_active', $input)
                && ! filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);

            if ($isBeingDisabled) {
                if (POSRegister::where('warehouse_id', $existing->id)->whereNull('closed_at')->exists()) {
                    abort(422, 'No puedes desactivar esta bodega mientras tenga turnos de caja abiertos.');
                }

                $otherActive = Warehouse::where('store_id', $existing->store_id)
                    ->active()->where('id', '<>', $existing->id)->orderBy('id')->first();
                if (! $otherActive) {
                    abort(422, 'No se puede desactivar la última bodega activa de la tienda.');
                }

                if ((int) getSettingValue('default_warehouse') === (int) $existing->id) {
                    Setting::updateOrCreate(
                        ['store_id' => $existing->store_id, 'key' => 'default_warehouse'],
                        ['value' => (string) $otherActive->id]
                    );
                }

                CatalogSetting::where('store_id', $existing->store_id)
                    ->where('warehouse_id', $existing->id)
                    ->update(['warehouse_id' => $otherActive->id]);
            }

            return $this->warehouseRepository->update($input, $id);
        });

        return new WarehouseResource($warehouse);
    }

    public function destroy($id): JsonResponse
    {
        $this->authorizeStoreOwnership($this->warehouseRepository->find($id));
        if (getSettingValue('default_warehouse') == $id) {
            return $this->SendError(__('messages.error.default_warehouse_cant_delete'));
        }

        $useWarehouse = $this->warehouseRepository->warehouseCanDelete($id);
        if ($useWarehouse) {
            return $this->sendError(__('messages.error.warehouse_cant_delete'));
        }
        $this->warehouseRepository->delete($id);

        return $this->sendSuccess('Warehouse deleted successfully');
    }

    public function warehouseReport(Request $request)
    {
        // Endpoint separado del resto de ReportAPIController -- se le
        // había pasado por alto el scoping por tienda: mostraba
        // conteos globales de TODAS las tiendas en las 4 tarjetas de
        // resumen ("todo el almacén"), aunque el warehouse_id puntual
        // sí ayuda a acotar dentro de una tienda, no valida que ese
        // almacén pertenezca a la tienda activa por sí solo.
        $report = [];
        if ($request->get('warehouse_id') && ! empty($request->get('warehouse_id')) && $request->get('warehouse_id') != 'null') {
            $warehouseId = $request->get('warehouse_id');
            $report['sale_count'] = $this->scopeQueryToCurrentStore(Sale::whereWarehouseId($warehouseId))->count();
            $report['purchase_count'] = $this->scopeQueryToCurrentStore(Purchase::whereWarehouseId($warehouseId))->count();
            $report['sale_return_count'] = $this->scopeQueryToCurrentStore(SaleReturn::whereWarehouseId($warehouseId))->count();
            $report['purchase_return_count'] = $this->scopeQueryToCurrentStore(PurchaseReturn::whereWarehouseId($warehouseId))->count();
        } else {
            $report['sale_count'] = $this->scopeQueryToCurrentStore(Sale::query())->count();
            $report['purchase_count'] = $this->scopeQueryToCurrentStore(Purchase::query())->count();
            $report['sale_return_count'] = $this->scopeQueryToCurrentStore(SaleReturn::query())->count();
            $report['purchase_return_count'] = $this->scopeQueryToCurrentStore(PurchaseReturn::query())->count();
        }

        return $this->sendResponse($report, '');
    }
}
