<?php

namespace App\Http\Controllers\API;

use App\Exports\ProductExcelExport;
use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductCollection;
use App\Http\Resources\ProductResource;
use App\Imports\ProductImport;
use App\Models\MainProduct;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\VariationProduct;
use App\Repositories\ProductRepository;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ProductAPIController extends AppBaseController
{
    /** @var ProductRepository */
    private $productRepository;

    public function __construct(ProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    public function index(Request $request): ProductCollection
    {
        $perPage = getPageSize($request);
        $products = $this->productRepository;

        // Estas 4 antes se consultaban UNA POR UNA por cada producto dentro
        // de prepareAttributes() (categoría, marca, producto principal,
        // variación) -- con catálogos grandes eso es la diferencia entre
        // cientos de consultas extra y ninguna. Cargarlas acá, de una sola
        // vez para toda la página, es lo mismo que ya se hacía pero sin el
        // costo repetido.
        $products->with(['productCategory', 'brand', 'mainProduct.media', 'variationProduct.variation', 'variationProduct.variationType'])
            ->withSum([
                'stocks as stocks_sum_quantity' => fn ($query) => $query
                    ->whereHas('warehouse', fn ($warehouseQuery) => $warehouseQuery->active()),
            ], 'quantity');

        if ($storeId = $this->currentStoreId()) {
            $products->where('store_id', $storeId);
        }

        if ($request->get('product_unit')) {
            $products->where('product_unit', $request->get('product_unit'));
        }

        if ($request->get('warehouse_id') && $request->get('warehouse_id') != 'null') {
            $warehouseId = (int) $request->get('warehouse_id');
            $this->authorizeWarehouseAccess($warehouseId);
            $products->whereHas('stock', function ($q) use ($warehouseId) {
                $q->where('manage_stocks.warehouse_id', $warehouseId);
            })->with([
                        'stock' => function (HasOne $query) use ($warehouseId) {
                            $query->where('manage_stocks.warehouse_id', $warehouseId);
                        },
                        'warehousePrices' => function ($query) use ($warehouseId) {
                            $query->where('warehouse_id', $warehouseId);
                        },
                        'presentations.warehousePrices' => function ($query) use ($warehouseId) {
                            $query->where('warehouse_id', $warehouseId);
                        },
                        'presentations.product',
                    ]);
        }

        $products = $products->paginate($perPage);
        ProductResource::usingWithCollection();

        return new ProductCollection($products);
    }

    /**
     * @return ProductResource|JsonResponse
     */
    public function store(CreateProductRequest $request)
    {
        $input = $request->all();

        if ($input['main_product_id']) {
            $mainProduct = MainProduct::find($input['main_product_id']);
            if ($mainProduct->product_type == MainProduct::SINGLE_PRODUCT) {
                return $this->sendError('You can add variations for single type product');
            }
        }

        if ($input['barcode_symbol'] == Product::EAN8 && strlen($input['code']) != 7) {
            return $this->sendError('Please enter 7 digit code');
        }

        if ($input['barcode_symbol'] == Product::UPC && strlen($input['code']) != 11) {
            return $this->sendError(' Please enter 11 digit code');
        }

        $product = $this->productRepository->storeProduct($input);

        VariationProduct::create([
            'product_id' => $product->id,
            'variation_id' => $input['variation_id'],
            'variation_type_id' => $input['variation_type'],
            'main_product_id' => $input['main_product_id'],
        ]);

        return new ProductResource($product);
    }

    public function show($id): ProductResource
    {
        $product = $this->productRepository->find($id);
        $this->authorizeStoreOwnership($product);

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, $id): ProductResource
    {
        $this->authorizeStoreOwnership($this->productRepository->find($id));
        $input = $request->all();

        $product = $this->productRepository->updateProduct($input, $id);

        return new ProductResource($product);
    }

    public function destroy($id): JsonResponse
    {
        $this->authorizeStoreOwnership($this->productRepository->find($id));

        $purchaseItemModels = [
            PurchaseItem::class,
        ];
        $saleItemModels = [
            SaleItem::class,
        ];
        $purchaseResult = canDelete($purchaseItemModels, 'product_id', $id);
        $saleResult = canDelete($saleItemModels, 'product_id', $id);
        if ($purchaseResult || $saleResult) {
            return $this->sendError(__('messages.error.product_cant_deleted'));
        }

        if (File::exists(Storage::path('product_barcode/barcode-PR_' . $id . '.png'))) {
            File::delete(Storage::path('product_barcode/barcode-PR_' . $id . '.png'));
        }

        $product = $this->productRepository->find($id);
        $mainProduct = MainProduct::withCount('products')->find($product->main_product_id);

        if ($mainProduct->product_type == MainProduct::VARIATION_PRODUCT && $mainProduct->products_count <= 1) {
            return $this->sendError('You can not delete last variation product');
        }

        VariationProduct::where('product_id', $id)->delete();

        $this->productRepository->delete($id);

        return $this->sendSuccess('Product deleted successfully');
    }

    public function productImageDelete($mediaId): JsonResponse
    {
        $media = Media::where('id', $mediaId)->firstOrFail();
        $owner = $media->model;
        if ($owner instanceof Product || $owner instanceof MainProduct) {
            $this->authorizeStoreOwnership($owner);
        } else {
            abort(403, 'No tiene permiso para eliminar este archivo.');
        }
        $media->delete();

        return $this->sendSuccess('Product image deleted successfully');
    }

    public function importProducts(Request $request): JsonResponse
    {
        Excel::import(new ProductImport, request()->file('file'));

        return $this->sendSuccess('Products imported successfully');
    }

    public function getProductExportExcel(Request $request): JsonResponse
    {
        $filename = 'product-excel-export-'.auth()->id().'.xlsx';
        $path = tenantMediaPath('excel/'.$filename);
        $disk = Storage::disk('tenant_private');
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
        Excel::store(new ProductExcelExport($this->requireCurrentStoreId()), $path, 'tenant_private');

        $data['product_excel_url'] = url('/api/tenant-files/excel/'.$filename);

        return $this->sendResponse($data, 'Product retrieved successfully');
    }

    public function getAllProducts()
    {
        $products = Product::where('store_id', $this->requireCurrentStoreId())->get();
        $data = [];

        foreach ($products as $product) {
            $data[] = [
                'id' => $product->id,
                'name' => $product->name,
            ];
        }

        return $this->sendResponse($data, 'Products retrieve successfully.');
    }
}
