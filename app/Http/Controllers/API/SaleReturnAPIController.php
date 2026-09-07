<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateSaleReturnRequest;
use App\Http\Requests\UpdateSaleReturnRequest;
use App\Http\Resources\SaleReturnCollection;
use App\Http\Resources\SaleReturnResource;
use App\Models\Customer;
use App\Models\ManageStock;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Repositories\SaleReturnRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SaleReturnAPIController extends AppBaseController
{
    /**
     * @var SaleReturnRepository
     */
    private $saleReturnRepository;

    /**
     * SaleReturnAPIController constructor.
     */
    public function __construct(SaleReturnRepository $saleReturnRepository)
    {
        $this->saleReturnRepository = $saleReturnRepository;
    }

    public function index(Request $request): SaleReturnCollection
    {
        $perPage = getPageSize($request);
        $search = $request->filter['search'] ?? '';
        $customer = (Customer::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $warehouse = (Warehouse::active()->where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $salesReturn = $this->saleReturnRepository;
        if ($storeId = $this->currentStoreId()) {
            $salesReturn->whereHas('warehouse', function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->active();
            });
        }
        if ($customer || $warehouse) {
            $salesReturn->whereHas('customer', function (Builder $q) use ($search, $customer) {
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
            $salesReturn->whereBetween('date', [$request->get('start_date'), $request->get('end_date')]);
        }

        if ($request->get('warehouse_id')) {
            $salesReturn->where('warehouse_id', $request->get('warehouse_id'));
        }

        if ($request->get('customer_id')) {
            $salesReturn->where('customer_id', $request->get('customer_id'));
        }

        if ($request->get('status') && $request->get('status') != 'null') {
            $salesReturn->Where('status', $request->get('status'));
        }

        if ($request->get('payment_status') && $request->get('payment_status') != 'null') {
            $salesReturn->where('payment_status', $request->get('payment_status'));
        }

        $salesReturn = $salesReturn->paginate($perPage);

        SaleReturnResource::usingWithCollection();

        return new SaleReturnCollection($salesReturn);
    }

    public function store(CreateSaleReturnRequest $request): SaleReturnResource
    {
        $this->authorizeWarehouseAccess($request->input('warehouse_id'));
        $this->authorizeStoreModelId(Customer::class, $request->input('customer_id'));
        $this->authorizeProductItems($request->input('sale_return_items', []));
        $input = $request->all();
        $saleReturn = $this->saleReturnRepository->storeSaleReturn($input);

        return new SaleReturnResource($saleReturn);
    }

    public function show($id): SaleReturnResource
    {
        $saleReturn = $this->saleReturnRepository->find($id);
        $this->authorizeWarehouseAccess($saleReturn->warehouse_id);

        return new SaleReturnResource($saleReturn);
    }

    public function edit(SaleReturn $salesReturn): SaleReturnResource
    {
        $this->authorizeWarehouseAccess($salesReturn->warehouse_id);
        $salesReturn = $salesReturn->load(
            'saleReturnItems.product',
            'saleReturnItems.productPresentation.variationType',
            'warehouse'
        );

        return new SaleReturnResource($salesReturn);
    }

    public function editBySale($saleId)
    {
        $sale = Sale::findOrFail($saleId);
        $this->authorizeWarehouseAccess($sale->warehouse_id);
        $salesReturn = SaleReturn::where('sale_id', $saleId)->first();
        if (empty($salesReturn)) {
            return $this->sendError('Sale Return is not created');
        }
        $salesReturn = $salesReturn->load(
            'saleReturnItems',
            'saleReturnItems.product',
            'saleReturnItems.productPresentation.variationType',
            'warehouse'
        );

        return new SaleReturnResource($salesReturn);
    }

    public function update(UpdateSaleReturnRequest $request, $id): SaleReturnResource
    {
        $existingReturn = SaleReturn::findOrFail($id);
        $this->authorizeWarehouseAccess($existingReturn->warehouse_id);
        if ($existingReturn->cash_movement_id) {
            throw new UnprocessableEntityHttpException('Una devolución vinculada a caja no puede editarse. Revierte primero su movimiento de efectivo.');
        }
        $this->authorizeWarehouseAccess($request->input('warehouse_id'));
        $this->authorizeStoreModelId(Customer::class, $request->input('customer_id'));
        $this->authorizeProductItems($request->input('sale_return_items', []));
        $input = $request->all();
        $saleReturn = $this->saleReturnRepository->updateSaleReturn($input, $id);

        return new SaleReturnResource($saleReturn);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            DB::beginTransaction();
            $saleReturn = $this->saleReturnRepository->with('saleReturnItems')->where('id', $id)->first();
            $this->authorizeWarehouseAccess($saleReturn->warehouse_id);
            if ($saleReturn->cash_movement_id) {
                throw new UnprocessableEntityHttpException('Una devolución vinculada a caja no puede eliminarse. Revierte primero su movimiento de efectivo.');
            }
            $sale = Sale::whereId($saleReturn->sale_id)->first();
            if ($sale) {
                $sale->update(['is_return' => 0]);
            }
            foreach ($saleReturn->saleReturnItems as $saleReturnItem) {
                $product = ManageStock::whereWarehouseId($saleReturn->warehouse_id)->whereProductId($saleReturnItem['product_id'])->first();
                if ($product) {
                    if ($product->quantity >= $saleReturnItem['quantity']) {
                        $totalQuantity = $product->quantity - $saleReturnItem['quantity'];
                        $product->update([
                            'quantity' => $totalQuantity,
                        ]);
                    }
                }
            }
            $this->saleReturnRepository->delete($id);
            DB::commit();

            return $this->sendSuccess('Sale Return Deleted successfully');
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function saleReturnInfo(SaleReturn $salesReturn): JsonResponse
    {
        $salesReturn = $salesReturn->load(
            'saleReturnItems.product.variationType',
            'saleReturnItems.productPresentation.variationType',
            'warehouse',
            'customer'
        );
        $keyName = [
            'email', 'company_name', 'phone', 'address',
        ];
        $salesReturn['company_info'] = collect($keyName)->mapWithKeys(fn ($key) => [$key => getSettingValue($key)])->all();

        return $this->sendResponse($salesReturn, 'Sale Return information retrieved successfully');
    }

    /**
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig
     */
    public function pdfDownload(SaleReturn $saleReturn): JsonResponse
    {
        $this->authorizeWarehouseAccess($saleReturn->warehouse_id);
        $saleReturn = $saleReturn->load(
            'customer',
            'saleReturnItems.product',
            'saleReturnItems.productPresentation.variationType'
        );
        $data = [];
        $path = tenantMediaPath('pdf/sale_return-'.$saleReturn->reference_code.'.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);
        $pdf = PDF::loadView('pdf.sale-return-pdf', compact('saleReturn'))->setOptions([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);
        $disk->put($path, $pdf->output());
        $data['sale_return_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'Sale return pdf retrieved Successfully');
    }

    public function getSaleReturnProductReport(Request $request): SaleReturnCollection
    {
        $perPage = getPageSize($request);
        $productId = $request->get('product_id');
        $saleReturns = $this->saleReturnRepository->whereHas('saleReturnItems', function ($q) use ($productId) {
            $q->where('product_id', '=', $productId);
        })->with(['saleReturnItems.product.variationType', 'customer']);

        $saleReturns = $saleReturns->paginate($perPage);

        SaleReturnResource::usingWithCollection();

        return new SaleReturnCollection($saleReturns);
    }
}
