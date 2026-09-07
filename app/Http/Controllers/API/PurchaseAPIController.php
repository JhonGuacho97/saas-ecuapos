<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreatePurchaseRequest;
use App\Http\Requests\UpdatePurchaseRequest;
use App\Http\Resources\PurchaseCollection;
use App\Http\Resources\PurchaseResource;
use App\Models\ManageStock;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Repositories\PurchaseRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class PurchaseAPIController
 */
class PurchaseAPIController extends AppBaseController
{
    /** @var PurchaseRepository */
    private $purchaseRepository;

    public function __construct(PurchaseRepository $purchaseRepository)
    {
        $this->purchaseRepository = $purchaseRepository;
    }

    public function index(Request $request): PurchaseCollection
    {
        $perPage = getPageSize($request);
        $search = $request->filter['search'] ?? '';
        $supplier = (Supplier::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $warehouse = (Warehouse::active()->where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $purchases = $this->purchaseRepository;
        if ($supplier || $warehouse) {
            $purchases->whereHas('supplier', function (Builder $q) use ($search, $supplier) {
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
            $purchases->whereBetween('date', [$request->get('start_date'), $request->get('end_date')]);
        }

        if ($request->get('warehouse_id')) {
            $purchases->where('warehouse_id', $request->get('warehouse_id'));
        }

        if ($request->get('status')) {
            $purchases->where('status', $request->get('status'));
        }

        if ($restricted = $this->restrictedWarehouseId()) {
            $purchases->where('warehouse_id', $restricted);
        }
        $this->scopeQueryToCurrentStore($purchases);

        $purchases = $purchases->paginate($perPage);

        PurchaseResource::usingWithCollection();

        return new PurchaseCollection($purchases);
    }

    public function store(CreatePurchaseRequest $request): PurchaseResource
    {
        $this->authorizeWarehouseAccess($request->input('warehouse_id'));
        $this->authorizeStoreModelId(Supplier::class, $request->input('supplier_id'));
        $this->authorizeProductItems($request->input('purchase_items', []));
        $input = $request->all();
        $purchase = $this->purchaseRepository->storePurchase($input);

        return new PurchaseResource($purchase);
    }

    public function show($id): PurchaseResource
    {
        $purchase = $this->purchaseRepository->find($id);
        $this->authorizeWarehouseAccess($purchase->warehouse_id);

        return new PurchaseResource($purchase);
    }

    public function edit(Purchase $purchase): PurchaseResource
    {
        $this->authorizeWarehouseAccess($purchase->warehouse_id);
        $purchase = $purchase->load(
            'purchaseItems.product.stocks',
            'purchaseItems.productPresentation.variationType',
            'warehouse'
        );

        return new PurchaseResource($purchase);
    }

    public function update(UpdatePurchaseRequest $request, $id): PurchaseResource
    {
        $this->authorizeWarehouseAccess(Purchase::findOrFail($id)->warehouse_id);
        $this->authorizeWarehouseAccess($request->input('warehouse_id'));
        $this->authorizeStoreModelId(Supplier::class, $request->input('supplier_id'));
        $this->authorizeProductItems($request->input('purchase_items', []));
        $input = $request->all();
        $purchase = $this->purchaseRepository->updatePurchase($input, $id);

        return new PurchaseResource($purchase);
    }

    public function destroy($id): JsonResponse
    {
        try {
            DB::beginTransaction();
            //manage stock
            $purchase = $this->purchaseRepository->with('purchaseItems')->where('id', $id)->first();
            $this->authorizeWarehouseAccess($purchase->warehouse_id);
            foreach ($purchase->purchaseItems as $purchaseItem) {
                $product = ManageStock::whereWarehouseId($purchase->warehouse_id)
                    ->whereProductId($purchaseItem['product_id'])
                    ->first();
                if ($product) {
                    if ($product->quantity >= $purchaseItem['quantity']) {
                        $totalQuantity = $product->quantity - $purchaseItem['quantity'];
                        $product->update([
                            'quantity' => $totalQuantity,
                        ]);
                    } else {
                        throw new UnprocessableEntityHttpException(__('messages.error.available_quantity'));
                    }
                }
            }
            $this->purchaseRepository->delete($id);
            DB::commit();

            return $this->sendSuccess('Purchase Deleted successfully');
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig
     */
    public function pdfDownload(Purchase $purchase): JsonResponse
    {
        $this->authorizeWarehouseAccess($purchase->warehouse_id);
        $purchase = $purchase->load(
            'purchaseItems.product',
            'purchaseItems.productPresentation.variationType',
            'supplier'
        );

        $data = [];
        $path = tenantMediaPath('pdf/Purchase-'.$purchase->reference_code.'.pdf');
        $disk = Storage::disk('tenant_private');
        $disk->delete($path);

        $pdf = PDF::loadView('pdf.purchase-pdf', compact('purchase'))->setOption([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);
        $disk->put($path, $pdf->output());
        $data['purchase_pdf_url'] = tenantPrivateDownloadUrl('pdf/'.basename($path));

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function purchaseInfo(Purchase $purchase): JsonResponse
    {
        $this->authorizeWarehouseAccess($purchase->warehouse_id);
        $purchase = $purchase->load([
            'purchaseItems.product.variationType',
            'purchaseItems.productPresentation.variationType',
            'warehouse',
            'supplier',
        ]);
        $keyName = [
            'email', 'company_name', 'phone', 'address',
        ];
        $purchase['company_info'] = collect($keyName)->mapWithKeys(fn ($key) => [$key => getSettingValue($key)])->all();

        return $this->sendResponse($purchase, 'Purchase information retrieved successfully');
    }

    public function getPurchaseProductReport(Request $request): PurchaseCollection
    {
        $perPage = getPageSize($request);
        $productId = $request->get('product_id');
        $purchases = $this->purchaseRepository->whereHas('purchaseItems', function ($q) use ($productId) {
            $q->where('product_id', '=', $productId);
        })->with(['purchaseItems.product.variationType', 'supplier']);

        if ($restricted = $this->restrictedWarehouseId()) {
            $purchases->where('warehouse_id', $restricted);
        }
        $this->scopeQueryToCurrentStore($purchases);

        $purchases = $purchases->paginate($perPage);

        PurchaseResource::usingWithCollection();

        return new PurchaseCollection($purchases);
    }
}
