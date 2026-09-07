<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateSaleRequest;
use App\Http\Requests\UpdateSaleRequest;
use App\Http\Resources\SaleCollection;
use App\Http\Resources\SaleResource;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Customer;
use App\Models\Hold;
use App\Models\Sale;
use App\Models\SalesPayment;
use App\Models\SaleReturnItem;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Repositories\SaleRepository;
use App\Services\ElectronicInvoiceRequestService;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class SaleAPIController
 */
class SaleAPIController extends AppBaseController
{
    /** @var saleRepository */
    private $saleRepository;

    public function __construct(
        SaleRepository $saleRepository,
        private readonly ElectronicInvoiceRequestService $invoiceRequests
    )
    {
        $this->saleRepository = $saleRepository;
    }

    public function index(Request $request): SaleCollection
    {
        // Esta ruta ahora también acepta manage_my-sales (ver routes/api.php,
        // pensado para que "Mis Ventas" -- SellerDashboard.js -- pueda
        // llamar este mismo index() filtrado a su propio usuario). Sin
        // esto, alguien con manage_my-sales pero SIN manage_sale podría
        // ver las ventas de TODOS simplemente omitiendo el filtro
        // user_id del request -- se fuerza acá, ignorando lo que haya
        // mandado el cliente.
        if (!Auth::user()->can('manage_sale')) {
            $request->merge(['user_id' => Auth::id()]);
        }

        $perPage = getPageSize($request);
        $search = $request->filter['search'] ?? '';
        $customer = (Customer::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $warehouse = (Warehouse::active()->where('name', 'LIKE', "%$search%")->get()->count() != 0);

        $sales = $this->saleRepository;
        $sales->withCount('payments');
        $sales->with('user');
        if ($customer || $warehouse) {
            $sales->whereHas('customer', function (Builder $q) use ($search, $customer) {
                if ($customer) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            })->whereHas('warehouse', function (Builder $q) use ($search, $warehouse) {
                if ($warehouse) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            });
        }

        if ($request->get('start_date') && $request->get('end_date')) {
            $sales->whereBetween('date', [$request->get('start_date'), $request->get('end_date')]);
        }

        if ($request->get('warehouse_id')) {
            $sales->where('warehouse_id', $request->get('warehouse_id'));
        }

        if ($request->get('customer_id')) {
            $sales->where('customer_id', $request->get('customer_id'));
        }

        if ($request->get('user_id')) {
            $sales->where('user_id', $request->get('user_id'));
        }

        if ($request->get('status') && $request->get('status') != 'null') {
            $sales->Where('status', $request->get('status'));
        }

        if ($request->get('payment_status') && $request->get('payment_status') != 'null') {
            $sales->where('payment_status', $request->get('payment_status'));
        }

        if ($request->get('payment_type') && $request->get('payment_type') != 'null') {
            $sales->where('payment_type', $request->get('payment_type'));
        }

        // Un usuario no-admin con sucursal asignada solo ve ventas de esa
        // sucursal, sin importar qué warehouse_id haya pedido en el filtro.
        if ($restricted = $this->restrictedWarehouseId()) {
            $sales->where('warehouse_id', $restricted);
        }

        // A diferencia de lo anterior (que no restringe admins),
        // aislamiento entre tiendas aplica siempre -- un admin de la
        // Tienda A no debe listar ventas de la Tienda B.
        if ($storeId = $this->currentStoreId()) {
            $sales->whereHas('warehouse', function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->active();
            });
        }

        $sales = $sales->paginate($perPage);

        // Totales de TODOS los registros que calzan con el filtro (no solo
        // los de la página actual) -- mismos filtros de arriba, sin paginar.
        $totalsQuery = $this->applySaleFilters(Sale::query(), $request);
        $filteredSaleIds = (clone $totalsQuery)->pluck('id');

        $totals = [
            'grand_total' => (float) $totalsQuery->sum('grand_total'),
            'paid_amount' => (float) $totalsQuery->sum('paid_amount'),
            'cash_amount' => (float) SalesPayment::whereIn('sale_id', $filteredSaleIds)
                ->where('payment_type', SalesPayment::CASH)
                ->sum('amount'),
            'transfer_amount' => (float) SalesPayment::whereIn('sale_id', $filteredSaleIds)
                ->where('payment_type', SalesPayment::BANK_TRANSFER)
                ->sum('amount'),
        ];

        SaleResource::usingWithCollection();

        return (new SaleCollection($sales))->additional(['meta' => ['totals' => $totals]]);
    }

    /**
     * Mismos filtros que index(), reutilizados para calcular totales
     * agregados sin paginar.
     */
    private function applySaleFilters(Builder $query, Request $request): Builder
    {
        $search = $request->filter['search'] ?? '';
        $customer = (Customer::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $warehouse = (Warehouse::active()->where('name', 'LIKE', "%$search%")->get()->count() != 0);

        if ($customer || $warehouse) {
            $query->whereHas('customer', function (Builder $q) use ($search, $customer) {
                if ($customer) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            })->whereHas('warehouse', function (Builder $q) use ($search, $warehouse) {
                if ($warehouse) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            });
        }

        if ($request->get('start_date') && $request->get('end_date')) {
            $query->whereBetween('date', [$request->get('start_date'), $request->get('end_date')]);
        }

        if ($request->get('warehouse_id')) {
            $query->where('warehouse_id', $request->get('warehouse_id'));
        }

        if ($request->get('customer_id')) {
            $query->where('customer_id', $request->get('customer_id'));
        }

        if ($request->get('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        if ($request->get('status') && $request->get('status') != 'null') {
            $query->where('status', $request->get('status'));
        }

        if ($request->get('payment_status') && $request->get('payment_status') != 'null') {
            $query->where('payment_status', $request->get('payment_status'));
        }

        if ($request->get('payment_type') && $request->get('payment_type') != 'null') {
            $query->where('payment_type', $request->get('payment_type'));
        }

        if ($restricted = $this->restrictedWarehouseId()) {
            $query->where('warehouse_id', $restricted);
        }
        $this->scopeQueryToCurrentStore($query);

        return $query;
    }

    /**
     * PDF del reporte de ventas -- mismos filtros que index() (sucursal,
     * rango de fechas, etc.), con el mismo total agregado que ya se
     * muestra en pantalla, para que web/Excel/PDF siempre coincidan.
     */
    public function salesReportPdf(Request $request): JsonResponse
    {
        $sales = $this->applySaleFilters(Sale::query(), $request)
            ->with('warehouse', 'customer', 'payments')
            ->latest('date')
            ->get();

        $totals = [
            'count' => $sales->count(),
            'grand_total' => (float) $sales->sum('grand_total'),
            'paid_amount' => (float) $sales->sum(fn ($sale) => $sale->payments->sum('amount')),
        ];

        $warehouseName = null;
        if ($request->get('warehouse_id')) {
            $warehouseName = Warehouse::find($request->get('warehouse_id'))?->name;
        }

        $filters = [
            'warehouse' => $warehouseName,
            'start_date' => $request->get('start_date'),
            'end_date' => $request->get('end_date'),
        ];

        $path = tenantMediaPath('pdf/sales-report.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);

        $pdf = Pdf::loadView('pdf.sale-report-pdf', compact('sales', 'totals', 'filters'))
            ->setOptions([
                'tempDir' => public_path(),
                'chroot' => public_path(),
            ]);

        $disk->put($path, $pdf->output());

        // El nombre del archivo es siempre el mismo (se sobreescribe en cada
        // descarga), así que el navegador puede quedarse con una copia en
        // caché de una descarga anterior. Un parámetro único en la URL
        // fuerza a que siempre pida el archivo fresco.
        $data['sale_report_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'PDF retrieved successfully');
    }

    public function store(CreateSaleRequest $request): SaleResource
    {
        $this->authorizeWarehouseAccess($request->input('warehouse_id'));
        $this->authorizeStoreModelId(Customer::class, $request->input('customer_id'));
        $this->authorizeProductItems($request->input('sale_items', []));
        if (isset($request->hold_ref_no)) {
            $holdExist = Hold::whereReferenceCode($request->hold_ref_no)
                ->where('warehouse_id', $request->input('warehouse_id'))->first();
            if (!empty($holdExist)) {
                $holdExist->delete();
            }
        }
        $input = $request->all();
        $sale = DB::transaction(function () use ($input, $request) {
            $sale = $this->saleRepository->storeSale($input);
            $this->invoiceRequests->request($sale, $request->input('requested_electronic_document'));

            return $sale;
        }, 3);

        return new SaleResource($sale);
    }

    public function show($id): SaleResource
    {
        $sale = $this->saleRepository->find($id);
        $this->authorizeWarehouseAccess($sale->warehouse_id);

        return new SaleResource($sale);
    }

    public function edit(Sale $sale): SaleResource
    {
        $this->authorizeWarehouseAccess($sale->warehouse_id);
        $sale = $sale->load('saleItems.product.stocks', 'saleItems.productPresentation.variationType', 'warehouse');

        return new SaleResource($sale);
    }

    public function update(UpdateSaleRequest $request, $id): SaleResource
    {
        $this->authorizeWarehouseAccess(Sale::findOrFail($id)->warehouse_id);
        $this->authorizeWarehouseAccess($request->input('warehouse_id'));
        $this->authorizeStoreModelId(Customer::class, $request->input('customer_id'));
        $this->authorizeProductItems($request->input('sale_items', []));
        $input = $request->all();
        $sale = $this->saleRepository->updateSale($input, $id);

        return new SaleResource($sale);
    }

    public function destroy($id): JsonResponse
    {
        try {
            DB::beginTransaction();
            $sale = $this->saleRepository->with('saleItems')->where('id', $id)->first();
            $this->authorizeWarehouseAccess($sale->warehouse_id);

            // "Ya devuelto" suma notas de crédito por devolución y
            // devoluciones de venta -- si una parte de lo vendido ya
            // volvió al stock por cualquiera de esos dos caminos, no
            // hay que volver a sumarla acá; si no, se duplica.
            $yaAcreditadoPorCreditNote = CreditNoteItem::whereHas('creditNote', function ($q) use ($id) {
                $q->where('sale_id', $id)
                    ->where('concepto', CreditNote::CONCEPTO_DEVOLUCION);
            })
                ->get()
                ->groupBy('product_id')
                ->map(fn ($items) => $items->sum('quantity'));

            $yaDevueltoPorSaleReturn = SaleReturnItem::whereHas('saleReturn', function ($q) use ($id) {
                $q->where('sale_id', $id);
            })
                ->get()
                ->groupBy('product_id')
                ->map(fn ($items) => $items->sum('quantity'));

            foreach ($sale->saleItems as $saleItem) {
                $yaDevuelto = $yaAcreditadoPorCreditNote->get($saleItem['product_id'], 0)
                    + $yaDevueltoPorSaleReturn->get($saleItem['product_id'], 0);
                $cantidadARestaurar = max(0, $saleItem['quantity'] - $yaDevuelto);
                manageStock($sale->warehouse_id, $saleItem['product_id'], $cantidadARestaurar);
            }
            if (File::exists(Storage::path('sales/barcode-' . $sale->reference_code . '.png'))) {
                File::delete(Storage::path('sales/barcode-' . $sale->reference_code . '.png'));
            }
            $this->saleRepository->delete($id);
            DB::commit();

            return $this->sendSuccess('Sale Deleted successfully');
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig
     */
    public function pdfDownload(Sale $sale): JsonResponse
    {
        $this->authorizeWarehouseAccess($sale->warehouse_id);
        $sale = $sale->load(
            'customer',
            'saleItems.product',
            'saleItems.productPresentation.variationType',
            'payments'
        );
        $data = [];

        $path = tenantMediaPath('pdf/Sale-' . $sale->reference_code . '.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);

        $pdf = PDF::loadView('pdf.sale-pdf', compact('sale'))->setOption([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);

        $disk->put($path, $pdf->output());
        $data['sale_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function saleInfo(Sale $sale): JsonResponse
    {
        $this->authorizeWarehouseAccess($sale->warehouse_id);
        $sale = $sale->load([
            'saleItems.product.variationType',
            'saleItems.productPresentation.variationType',
            'warehouse',
            'customer',
            'payments',
            'electronicInvoice',
            'user',
        ]);
        $keyName = [
            'email',
            'company_name',
            'phone',
            'address',
            'sri_ruc',
            'sri_razon_social',
            'sri_obligado_contabilidad',
            'sri_nombre_comercial',
            'sri_dir_matriz',
        ];
        $sale['company_info'] = collect($keyName)->mapWithKeys(fn ($key) => [$key => getSettingValue($key)])->all();

        // El PNG del código de barras ya se genera y se guarda al crear
        // la venta (SaleRepository::generateBarcode) -- acá solo se
        // expone su URL pública, que antes no viajaba en la respuesta.
        $barcodePath = 'sales/barcode-' . $sale->reference_code . '.png';
        if (Storage::disk(config('app.media_disc'))->exists($barcodePath)) {
            $sale['barcode_url'] = Storage::disk(config('app.media_disc'))->url($barcodePath);
        }

        // Número real del comprobante (ej. "001-001-000050001"), para
        // mostrar "FACTURA 001-001-000050001" en vez del reference_code
        // interno cuando esta venta ya tiene un secuencial asignado.
        if ($sale->electronicInvoice) {
            $sale->electronicInvoice->setAttribute(
                'numero_comprobante',
                $sale->electronicInvoice->numeroComprobante()
            );
        }

        return $this->sendResponse($sale, 'Sale information retrieved successfully');
    }

    public function getSaleProductReport(Request $request): SaleCollection
    {
        $perPage = getPageSize($request);
        $productId = $request->get('product_id');
        $sales = $this->saleRepository->whereHas('saleItems', function ($q) use ($productId) {
            $q->where('product_id', '=', $productId);
        })->with(['saleItems.product.variationType', 'customer']);

        if ($restricted = $this->restrictedWarehouseId()) {
            $sales->where('warehouse_id', $restricted);
        }
        $this->scopeQueryToCurrentStore($sales);

        $sales = $sales->paginate($perPage);

        SaleResource::usingWithCollection();

        return new SaleCollection($sales);
    }
}
