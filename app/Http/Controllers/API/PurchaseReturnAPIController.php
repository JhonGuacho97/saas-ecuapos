<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreatePurchaseReturnRequest;
use App\Http\Requests\UpdatePurchaseReturnRequest;
use App\Http\Resources\PurchaseReturnCollection;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Repositories\PurchaseReturnRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PurchaseReturnAPIController extends AppBaseController
{
    /** @var PurchaseReturnRepository */
    private $purchaseReturnRepository;

    /**
     * PurchaseReturnAPIController constructor.
     */
    public function __construct(PurchaseReturnRepository $purchaseReturnRepository)
    {
        $this->purchaseReturnRepository = $purchaseReturnRepository;
    }

    public function index(Request $request): PurchaseReturnCollection
    {
        $storeId = $this->requireCurrentStoreId();
        $perPage = getPageSize($request);
        $search = $request->filter['search'] ?? '';
        $supplier = Supplier::where('store_id', $storeId)->where('name', 'LIKE', "%$search%")->exists();
        $warehouse = Warehouse::where('store_id', $storeId)->active()->where('name', 'LIKE', "%$search%")->exists();
        $purchasesReturn = $this->purchaseReturnRepository;
        $this->scopeQueryToCurrentStore($purchasesReturn);
        if ($supplier || $warehouse) {
            $purchasesReturn->whereHas('supplier', function (Builder $q) use ($search, $supplier) {
                if ($supplier) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            })->whereHas('warehouse', function (Builder $q) use ($search, $warehouse) {
                if ($warehouse) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            });
        }

        if ($request->get('start_date') && $request->get('end_date')) {
            $purchasesReturn->whereBetween('date',
                [$request->get('start_date'), $request->get('end_date')]);
        }

        if ($request->get('warehouse_id')) {
            $purchasesReturn->where('warehouse_id', $request->get('warehouse_id'));
        }

        if ($request->get('status')) {
            $purchasesReturn->where('status', $request->get('status'));
        }

        $purchasesReturn = $purchasesReturn->paginate($perPage);
        PurchaseReturnResource::usingWithCollection();

        return new PurchaseReturnCollection($purchasesReturn);
    }

    public function store(CreatePurchaseReturnRequest $request): PurchaseReturnResource
    {
        $input = $request->all();
        $this->authorizeWarehouseAccess((int) $request->input('warehouse_id'));
        $this->authorizeStoreModelId(Supplier::class, $request->input('supplier_id'));
        $this->authorizeProductItems($input['purchase_return_items'] ?? []);
        $purchaseReturn = $this->purchaseReturnRepository->storePurchaseReturn($input);

        return new PurchaseReturnResource($purchaseReturn);
    }

    public function show($id): PurchaseReturnResource
    {
        $purchaseReturn = $this->purchaseReturnRepository->find($id);
        $this->authorizeWarehouseAccess($purchaseReturn->warehouse_id);

        return new PurchaseReturnResource($purchaseReturn);
    }

    public function edit(PurchaseReturn $purchasesReturn): PurchaseReturnResource
    {
        $this->authorizeWarehouseAccess($purchasesReturn->warehouse_id);
        $purchasesReturn = $purchasesReturn->load(
            'purchaseReturnItems.product.stocks',
            'purchaseReturnItems.productPresentation.variationType',
            'warehouse'
        );

        return new PurchaseReturnResource($purchasesReturn);
    }

    public function update(UpdatePurchaseReturnRequest $request, $id): PurchaseReturnResource
    {
        $existing = PurchaseReturn::findOrFail($id);
        $this->authorizeWarehouseAccess($existing->warehouse_id);
        $this->authorizeWarehouseAccess((int) $request->input('warehouse_id'));
        $this->authorizeStoreModelId(Supplier::class, $request->input('supplier_id'));
        $input = $request->all();
        $this->authorizeProductItems($input['purchase_return_items'] ?? []);
        $purchaseReturn = $this->purchaseReturnRepository->updatePurchaseReturn($input, $id);

        return new PurchaseReturnResource($purchaseReturn);
    }

    public function destroy($id): JsonResponse
    {
        try {
            DB::beginTransaction();
            $purchaseReturn = $this->purchaseReturnRepository->where('id', $id)->with('purchaseReturnItems')->first();
            $this->authorizeWarehouseAccess($purchaseReturn?->warehouse_id);
            foreach ($purchaseReturn->purchaseReturnItems as $purchaseReturnItem) {
                manageStock(
                    $purchaseReturn->warehouse_id,
                    $purchaseReturnItem['product_id'],
                    $purchaseReturnItem['quantity']
                );
            }
            $this->purchaseReturnRepository->delete($purchaseReturn->id);
            DB::commit();

            return $this->sendSuccess('Purchase Return Deleted successfully');
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function purchaseReturnInfo(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->authorizeWarehouseAccess($purchaseReturn->warehouse_id);
        $purchaseReturn = $purchaseReturn->load([
            'purchaseReturnItems.product.variationType',
            'purchaseReturnItems.productPresentation.variationType',
            'warehouse',
            'supplier',
        ]);
        $keyName = [
            'email', 'company_name', 'phone', 'address',
        ];
        $purchaseReturn['company_info'] = collect($keyName)->mapWithKeys(fn ($key) => [$key => getSettingValue($key)])->all();

        return $this->sendResponse($purchaseReturn, 'Purchase Return information retrieved successfully');
    }

    /**
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig
     */
    public function pdfDownload(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->authorizeWarehouseAccess($purchaseReturn->warehouse_id);
        $purchaseReturn = $purchaseReturn->load(
            'purchaseReturnItems.product',
            'purchaseReturnItems.productPresentation.variationType',
            'supplier'
        );

        $data = [];
        $path = tenantMediaPath('pdf/purchase_return-'.$purchaseReturn->reference_code.'.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);

        $pdf = PDF::loadView('pdf.purchase-return-pdf', compact('purchaseReturn'))->setOptions([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);

        $disk->put($path, $pdf->output());
        $data['purchase_return_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'purchase return pdf retrieved Successfully');
    }

    public function getPurchaseReturnProductReport(Request $request): PurchaseReturnCollection
    {
        $perPage = getPageSize($request);
        $productId = $request->get('product_id');
        $purchaseReturn = $this->purchaseReturnRepository->whereHas('purchaseReturnItems',
            function ($q) use ($productId) {
                $q->where('product_id', '=', $productId);
            })->with(['purchaseReturnItems.product.variationType', 'supplier']);
        $this->scopeQueryToCurrentStore($purchaseReturn);

        $purchaseReturn = $purchaseReturn->paginate($perPage);
        PurchaseReturnResource::usingWithCollection();

        return new PurchaseReturnCollection($purchaseReturn);
    }
}
