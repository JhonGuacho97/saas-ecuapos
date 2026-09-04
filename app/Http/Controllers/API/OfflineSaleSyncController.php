<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\OfflineCreateSaleRequest;
use App\Http\Requests\OfflineSaleDiagnosisRequest;
use App\Http\Resources\SaleResource;
use App\Models\ElectronicInvoice;
use App\Models\Sale;
use App\Repositories\SaleRepository;
use App\Services\ElectronicInvoiceRequestService;
use App\Services\OfflineSaleStockDiagnosisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class OfflineSaleSyncController extends AppBaseController
{
    public function __construct(
        private readonly SaleRepository $saleRepository,
        private readonly ElectronicInvoiceRequestService $invoiceRequests,
        private readonly OfflineSaleStockDiagnosisService $stockDiagnosis
    ) {}

    public function store(OfflineCreateSaleRequest $request): SaleResource|JsonResponse
    {
        $storeId = $this->authorizeSyncCredential($request);

        $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'created_offline' => ['accepted'],
            'offline_created_at' => ['required', 'date'],
            'requested_electronic_document' => ['nullable', 'in:' . ElectronicInvoice::FACTURA],
        ]);
        $this->authorizeWarehouseAccess((int) $request->input('warehouse_id'));

        // La respuesta de un checkout online puede perderse después de que la
        // transacción ya hizo commit. En ese caso el frontend encola el mismo
        // UUID. Resolver la identidad ANTES del diagnóstico de stock evita
        // interpretar como falta de inventario el stock que esta misma venta
        // ya descontó y, sobre todo, impide registrarla una segunda vez.
        $existingSale = Sale::query()
            ->where('client_uuid', $request->input('client_uuid'))
            ->where('user_id', $request->user()->id)
            ->whereHas('warehouse', fn ($query) => $query->where('store_id', $storeId))
            ->first();
        if ($existingSale) {
            return new SaleResource($existingSale);
        }

        if ($offlineCustomerUuid = $request->input('offline_customer_uuid')) {
            $customerId = DB::table('offline_customer_identities')
                ->where('store_id', $storeId)
                ->where('client_uuid', $offlineCustomerUuid)
                ->value('customer_id');
            if (! $customerId) {
                abort(422, 'El cliente offline todavía no se ha sincronizado.');
            }
            $request->merge(['customer_id' => $customerId]);
        }
        $customerBelongsToStore = DB::table('customers')
            ->where('id', $request->input('customer_id'))
            ->where('store_id', $storeId)
            ->exists();
        if (! $customerBelongsToStore) {
            abort(422, 'El cliente no pertenece a la tienda activa.');
        }

        $diagnosis = $this->stockDiagnosis->diagnose(
            $request->input('sale_items', []),
            (int) $request->input('warehouse_id'),
            $storeId
        );
        if (! $diagnosis['can_sync']) {
            return response()->json([
                'success' => false,
                'message' => 'Uno o más productos no tienen stock suficiente en el almacén seleccionado.',
                'error_code' => 'INSUFFICIENT_STOCK',
                'conflicts' => $diagnosis['conflicts'],
                'diagnosis' => $diagnosis,
            ], 422);
        }

        $sale = DB::transaction(function () use ($request) {
            $sale = $this->saleRepository->storeSale($request->all());
            $this->invoiceRequests->request($sale, $request->input('requested_electronic_document'));

            return $sale;
        }, 3);

        return new SaleResource($sale->fresh());
    }

    public function diagnose(OfflineSaleDiagnosisRequest $request): JsonResponse
    {
        $storeId = $this->authorizeSyncCredential($request);
        $this->authorizeWarehouseAccess((int) $request->input('warehouse_id'));

        $diagnosis = $this->stockDiagnosis->diagnose(
            $request->input('sale_items', []),
            (int) $request->input('warehouse_id'),
            $storeId
        );

        return response()->json([
            'success' => true,
            'message' => $diagnosis['can_sync']
                ? 'La venta tiene stock suficiente para sincronizarse.'
                : 'La venta todavía tiene conflictos de inventario.',
            'data' => $diagnosis,
        ]);
    }

    public function status(Request $request, string $clientUuid): JsonResponse
    {
        $storeId = $this->authorizeSyncCredential($request);
        abort_unless(Str::isUuid($clientUuid), 422, 'El identificador de la venta no es válido.');

        $sale = Sale::query()
            ->where('client_uuid', $clientUuid)
            ->where('user_id', $request->user()->id)
            ->whereHas('warehouse', fn ($query) => $query->where('store_id', $storeId))
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'exists' => (bool) $sale,
                'sale' => $sale ? (new SaleResource($sale))->resolve($request)['data'] : null,
            ],
        ]);
    }

    private function authorizeSyncCredential($request): int
    {
        $token = $request->user()?->currentAccessToken();
        $storeId = $this->requireCurrentStoreId();

        if (! $token || ! str_starts_with($token->name, 'offline-sync:')) {
            throw new AccessDeniedHttpException('Esta ruta requiere una credencial de sincronización del dispositivo.');
        }
        if (! $request->user()->tokenCan("store:{$storeId}")) {
            throw new AccessDeniedHttpException('La credencial no pertenece a la tienda activa.');
        }

        return $storeId;
    }
}
