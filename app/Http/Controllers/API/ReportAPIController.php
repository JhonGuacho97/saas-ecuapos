<?php

namespace App\Http\Controllers\API;

use App\Exports\ExpenseWarehouseReportExport;
use App\Exports\ProductPurchaseReportExport;
use App\Exports\ProductPurchaseReturnReportExport;
use App\Exports\ProductSaleReportExport;
use App\Exports\ProductSaleReturnReportExport;
use App\Exports\PurchaseReportExport;
use App\Exports\PurchaseReturnWarehouseReportExport;
use App\Exports\PurchasesWarehouseReportExport;
use App\Exports\SaleReportExport;
use App\Exports\SaleReturnWarehouseReportExport;
use App\Exports\SalesWarehouseReportExport;
use App\Exports\StockReportExport;
use App\Exports\TopSellingProductReportExport;
use App\Http\Controllers\AppBaseController;
use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ManageStock;
use App\Models\POSRegister;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SalesPayment;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ProfitLossReportService;
use App\Repositories\CustomerRepository;
use App\Repositories\ManageStockRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\PurchaseReturnRepository;
use App\Repositories\SupplierRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\QueryBuilder\QueryBuilder;

class ReportAPIController extends AppBaseController
{
    private $manageStockRepository;

    private $purchaseRepository;

    private $purchaseReturnRepository;

    private $supplierRepository;

    /**
     * ReportAPIController constructor.
     */
    public function __construct(
        ManageStockRepository $manageStockRepository,
        PurchaseRepository $purchaseRepository,
        PurchaseReturnRepository $purchaseReturnRepository,
        SupplierRepository $supplierRepository,
        CustomerRepository $customerRepository
    ) {
        $this->manageStockRepository = $manageStockRepository;
        $this->purchaseRepository = $purchaseRepository;
        $this->purchaseReturnRepository = $purchaseReturnRepository;
        $this->supplierRepository = $supplierRepository;
        $this->customerRepository = $customerRepository;
    }

    public function getWarehouseSaleReportExcel(Request $request): JsonResponse
    {
        if (Storage::exists('excel/sale-report-pdf.xlsx')) {
            Storage::delete('excel/sale-report-pdf.xlsx');
        }
        Excel::store(new SalesWarehouseReportExport, 'excel/sale-report-excel.xlsx');

        $data['sale_excel_url'] = Storage::url('excel/sale-report-excel.xlsx');

        return $this->sendResponse($data, 'Sale Report retrieved successfully');
    }

    public function getWarehousePurchaseReportExcel(Request $request): JsonResponse
    {
        if (Storage::exists('excel/purchase-report-pdf.xlsx')) {
            Storage::delete('excel/purchase-report-pdf.xlsx');
        }
        Excel::store(new PurchasesWarehouseReportExport, 'excel/purchase-report-excel.xlsx');

        $data['purchase_excel_url'] = Storage::url('excel/purchase-report-excel.xlsx');

        return $this->sendResponse($data, 'purchase Report retrieved successfully');
    }

    public function getWarehouseSaleReturnReportExcel(Request $request): JsonResponse
    {
        if (Storage::exists('excel/sale-return-report-excel.xlsx')) {
            Storage::delete('excel/sale-return-report-excel.xlsx');
        }
        Excel::store(new SaleReturnWarehouseReportExport, 'excel/sale-return-report-excel.xlsx');

        $data['sale_return_excel_url'] = Storage::url('excel/sale-return-report-excel.xlsx');

        return $this->sendResponse($data, 'sale return Report retrieved successfully');
    }

    public function getWarehousePurchaseReturnReportExcel(Request $request): JsonResponse
    {
        if (Storage::exists('excel/purchase-return-report-excel.xlsx')) {
            Storage::delete('excel/purchase-return-report-excel.xlsx');
        }
        Excel::store(new PurchaseReturnWarehouseReportExport, 'excel/purchase-return-report-excel.xlsx');

        $data['purchase_return_excel_url'] = Storage::url('excel/purchase-return-report-excel.xlsx');

        return $this->sendResponse($data, 'purchase return Report retrieved successfully');
    }

    public function getWarehouseExpenseReportExcel(Request $request): JsonResponse
    {
        if (Storage::exists('excel/expense-report-excel.xlsx')) {
            Storage::delete('excel/expense-report-excel.xlsx');
        }
        Excel::store(new ExpenseWarehouseReportExport, 'excel/expense-report-excel.xlsx');

        $data['expense_excel_url'] = Storage::url('excel/expense-report-excel.xlsx');

        return $this->sendResponse($data, 'expenses Report retrieved successfully');
    }

    public function getSalesReportExcel(Request $request): JsonResponse
    {
        if (Storage::exists('excel/total-sales-report-excel.xlsx')) {
            Storage::delete('excel/total-sales-report-excel.xlsx');
        }
        Excel::store(new SaleReportExport, 'excel/total-sales-report-excel.xlsx');

        $data['total_sale_excel_url'] = Storage::url('excel/total-sales-report-excel.xlsx');

        return $this->sendResponse($data, 'Sale Report retrieved successfully');
    }

    public function getPurchaseReportExcel(Request $request): JsonResponse
    {
        if (Storage::exists('excel/purchases-report-excel.xlsx')) {
            Storage::delete('excel/purchases-report-excel.xlsx');
        }
        Excel::store(new PurchaseReportExport, 'excel/purchases-report-excel.xlsx');

        $data['total_purchase_excel_url'] = Storage::url('excel/purchases-report-excel.xlsx');

        return $this->sendResponse($data, 'Purchase Report retrieved successfully');
    }

    public function getSellingProductReportExcel(): JsonResponse
    {
        if (Storage::exists('excel/top-selling-product-report-excel.xlsx')) {
            Storage::delete('excel/top-selling-product-report-excel.xlsx');
        }
        Excel::store(new TopSellingProductReportExport, 'excel/top-selling-product-report-excel.xlsx');

        $data['top_selling_product_excel_url'] = Storage::url('excel/top-selling-product-report-excel.xlsx');

        return $this->sendResponse($data, 'Top selling product Report retrieved successfully');
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getSellingProductReport(Request $request)
    {
        $storeId = $this->currentStoreId();
        if ($request->get('start_date') && $request->get('start_date') != 'null') {
            // El rango lo elige el usuario en su calendario local (Ecuador)
            // pero sale_items.created_at se guarda en UTC -- hay que
            // convertir el rango local a UTC antes de comparar, si no el
            // filtro queda corrido hasta 5 horas.
            $startDate = Carbon::parse(request()->get('start_date'), 'America/Guayaquil')->startOfDay()->utc()->toDateTimeString();
            $endDate = Carbon::parse(request()->get('end_date'), 'America/Guayaquil')->endOfDay()->utc()->toDateTimeString();
            $topSelling = Product::leftJoin('sale_items', 'products.id', '=', 'sale_items.product_id')
                ->where('sale_items.created_at', '>=', $startDate)
                ->where('sale_items.created_at', '<=', $endDate)
                ->when($storeId, function ($q) use ($storeId) {
                    $q->where('products.store_id', $storeId);
                })
                ->selectRaw('products.*, COALESCE(sum(sale_items.sub_total),0) grand_total')
                ->selectRaw('products.*, COALESCE(sum(sale_items.quantity),0) total_quantity')
                ->groupBy('products.id')
                ->orderBy('total_quantity', 'desc')
                ->latest()
                ->take(10)
                ->get();
        } else {
            $topSelling = Product::leftJoin('sale_items', 'products.id', '=', 'sale_items.product_id')
                ->when($storeId, function ($q) use ($storeId) {
                    $q->where('products.store_id', $storeId);
                })
                ->selectRaw('products.*, COALESCE(sum(sale_items.sub_total),0) grand_total')
                ->selectRaw('products.*, COALESCE(sum(sale_items.quantity),0) total_quantity')
                ->groupBy('products.id')
                ->orderBy('total_quantity', 'desc')
                ->latest()
                ->take(10)
                ->get();
        }

        $topSellingProducts = [];
        foreach ($topSelling as $item) {
            if (isset($item->total_quantity) && $item->total_quantity != 0) {
                $topSellingProducts[] = $item->prepareTopSellingReport();
            }
        }

        return [
            'success' => true,
            'data' => $topSellingProducts,
            'total' => count($topSellingProducts),
        ];
    }

    public function stockReportExcel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,healthy,low,critical,out,negative'],
        ]);
        $this->authorizeWarehouseAccess((int) $validated['warehouse_id']);

        // Un nombre por tienda/usuario evita que dos clientes descarguen el
        // archivo temporal generado por otra sesión en hosting compartido.
        $fileName = sprintf(
            'excel/stock-report-%s-%s.xlsx',
            $this->currentStoreId() ?? 'store',
            Auth::id() ?? 'user'
        );
        if (Storage::exists($fileName)) {
            Storage::delete($fileName);
        }
        Excel::store(new StockReportExport($validated), $fileName);

        $data['stock_report_excel_url'] = Storage::url($fileName);

        return $this->sendResponse($data, 'Stock Report retrieved successfully');
    }

    public function getProductSaleReportExport(): JsonResponse
    {
        if (Storage::exists('excel/product-sales-report-excel.xlsx')) {
            Storage::delete('excel/product-sales-report-excel.xlsx');
        }
        Excel::store(new ProductSaleReportExport, 'excel/product-sales-report-excel.xlsx');

        $data['product_sale_report_excel_url'] = Storage::url('excel/product-sales-report-excel.xlsx');

        return $this->sendResponse($data, 'Product sales Report retrieved successfully');
    }

    public function getPurchaseProductReportExport(): JsonResponse
    {
        if (Storage::exists('excel/product-purchases-report-excel.xlsx')) {
            Storage::delete('excel/product-purchases-report-excel.xlsx');
        }
        Excel::store(new ProductPurchaseReportExport, 'excel/product-purchases-report-excel.xlsx');

        $data['product_purchase_report_url'] = Storage::url('excel/product-purchases-report-excel.xlsx');

        return $this->sendResponse($data, 'Product purchases retrieved successfully');
    }

    public function getSaleReturnProductReportExport(): JsonResponse
    {
        if (Storage::exists('excel/product-sale-return-report-excel.xlsx')) {
            Storage::delete('excel/product-sale-return-report-excel.xlsx');
        }
        Excel::store(new ProductSaleReturnReportExport, 'excel/product-sale-return-report-excel.xlsx');

        $data['product_sale_return_report_url'] = Storage::url('excel/product-sale-return-report-excel.xlsx');

        return $this->sendResponse($data, 'Product sale returns retrieved successfully');
    }

    public function getPurchaseReturnProductReportExport(): JsonResponse
    {
        if (Storage::exists('excel/product-purchase-return-report-excel.xlsx')) {
            Storage::delete('excel/product-purchase-return-report-excel.xlsx');
        }
        Excel::store(new ProductPurchaseReturnReportExport, 'excel/product-purchase-return-report-excel.xlsx');

        $data['product_purchase_return_report_url'] = Storage::url('excel/product-purchase-return-report-excel.xlsx');

        return $this->sendResponse($data, 'Product sale returns retrieved successfully');
    }

    public function getProductQuantity(Request $request): JsonResponse
    {
        $productId = (int) $request->get('product_id');
        $productModel = Product::findOrFail($productId);
        $this->authorizeStoreOwnership($productModel);
        $product = ManageStock::whereProductId($productId)->with('warehouse', 'product.productCategory')
            ->when($this->currentStoreId(), function ($q, $storeId) {
                $q->whereHas('warehouse', function ($qw) use ($storeId) {
                    $qw->where('store_id', $storeId)->active();
                });
            })
            ->get();

        $units = BaseUnit::query()->pluck('name', 'id');
        $product->each(function (ManageStock $stock) use ($units) {
            $stock->setAttribute('product_unit_name', $units->get($stock->product->product_unit, ''));
            if ($stock->product->is_kit) {
                $stock->setAttribute('quantity', $stock->product->buildableQuantity($stock->warehouse_id));
            }
        });

        return $this->sendResponse($product, 'Product Quantity retrieved successfully');
    }

    /**
     * @param  null  $warehouseId
     */
    public function stockAlerts(Request $request, $warehouseId = null): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'severity' => ['nullable', 'in:all,out,critical,low'],
            'page.size' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page.number' => ['nullable', 'integer', 'min:1'],
        ]);

        $warehouseId = $warehouseId !== null ? (int) $warehouseId : null;
        if ($warehouseId !== null) {
            $this->authorizeWarehouseAccess($warehouseId);
        }

        $query = ManageStock::query()
            ->with(['warehouse', 'product.productCategory', 'product.variationType'])
            // Se calcula en vivo. La columna alert puede quedar desactualizada
            // si alguien cambia el mínimo del producto sin mover existencias.
            ->whereHas('product', function (Builder $productQuery) {
                $productQuery->whereRaw(
                    'manage_stocks.quantity <= CAST(COALESCE(products.stock_alert, 0) AS DECIMAL(20,4))'
                );
                if ($storeId = $this->currentStoreId()) {
                    $productQuery->where('store_id', $storeId);
                }
            });

        if ($storeId = $this->currentStoreId()) {
            $query->whereHas('warehouse', fn (Builder $warehouseQuery) =>
                $warehouseQuery->where('store_id', $storeId)->active()
            );
        }
        if ($restrictedWarehouse = $this->restrictedWarehouseId()) {
            $query->where('warehouse_id', $restrictedWarehouse);
        }
        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->whereHas('product', function (Builder $productQuery) use ($search) {
                $like = '%'.$search.'%';
                $productQuery->where(function (Builder $searchQuery) use ($like) {
                    $searchQuery->where('code', 'like', $like)
                        ->orWhere('product_code', 'like', $like)
                        ->orWhere('name', 'like', $like);
                });
            });
        }
        if ($request->filled('category_id')) {
            $query->whereHas('product', fn (Builder $productQuery) =>
                $productQuery->where('product_category_id', (int) $request->get('category_id'))
            );
        }

        $summaryStocks = (clone $query)->get();
        $summary = ['total' => 0, 'out' => 0, 'critical' => 0, 'low' => 0, 'shortage' => 0.0];
        foreach ($summaryStocks as $stock) {
            $quantity = (float) $stock->quantity;
            $threshold = (float) ($stock->product->stock_alert ?? 0);
            $severity = $quantity <= 0 ? 'out' : ($quantity <= $threshold * 0.5 ? 'critical' : 'low');
            $summary['total']++;
            $summary[$severity]++;
            $summary['shortage'] += max($threshold - $quantity, 0);
        }
        $summary['shortage'] = round($summary['shortage'], 4);

        $severity = $request->get('severity');
        if ($severity === 'out') {
            $query->where('manage_stocks.quantity', '<=', 0);
        } elseif ($severity === 'critical') {
            $query->where('manage_stocks.quantity', '>', 0)
                ->whereHas('product', fn (Builder $productQuery) => $productQuery->whereRaw(
                    'manage_stocks.quantity <= CAST(COALESCE(products.stock_alert, 0) AS DECIMAL(20,4)) * 0.5'
                ));
        } elseif ($severity === 'low') {
            $query->where('manage_stocks.quantity', '>', 0)
                ->whereHas('product', fn (Builder $productQuery) => $productQuery->whereRaw(
                    'manage_stocks.quantity > CAST(COALESCE(products.stock_alert, 0) AS DECIMAL(20,4)) * 0.5'
                ));
        }

        $perPage = (int) $request->input('page.size', 10);
        $page = (int) $request->input('page.number', 1);
        $stocks = $query->orderBy('manage_stocks.quantity')->paginate($perPage, ['*'], 'page', $page);
        $units = BaseUnit::query()->pluck('name', 'id');
        $rows = $stocks->getCollection()->map(function (ManageStock $stock) use ($units) {
            $product = $stock->product;
            $quantity = (float) $stock->quantity;
            $threshold = (float) ($product->stock_alert ?? 0);
            return [
                'id' => $stock->id,
                'product_id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'variation_label' => $product->variationType?->name,
                'category_name' => $product->productCategory?->name ?? 'Sin categoría',
                'warehouse_id' => $stock->warehouse_id,
                'warehouse_name' => $stock->warehouse?->name,
                'quantity' => round($quantity, 4),
                'stock_alert' => round($threshold, 4),
                'shortage' => round(max($threshold - $quantity, 0), 4),
                'unit_name' => $units->get($product->product_unit, ''),
                'severity' => $quantity <= 0 ? 'out' : ($quantity <= $threshold * 0.5 ? 'critical' : 'low'),
                'updated_at' => optional($stock->updated_at)->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'summary' => $summary,
            'meta' => [
                'current_page' => $stocks->currentPage(),
                'last_page' => $stocks->lastPage(),
                'per_page' => $stocks->perPage(),
                'total' => $stocks->total(),
            ],
        ]);
    }

    public function getTodaySalesOverallReport()
    {
        $data = [];
        $storeId = $this->currentStoreId();
        $today = Carbon::today('America/Guayaquil');
        // sale_items.created_at es un timestamp real en UTC -- whereDate()
        // sobre esa columna compara contra SU fecha en UTC, no en Ecuador,
        // así que hay que filtrar por el rango UTC equivalente al día local.
        $todayStartUtc = $today->copy()->utc();
        $todayEndUtc = $today->copy()->endOfDay()->utc();

        $salesDiscount = $this->scopeQueryToCurrentStore(Sale::where('date', $today))->sum('discount');
        $salesTax = $this->scopeQueryToCurrentStore(Sale::where('date', $today))->sum('tax_amount');
        $salesShippingAmount = $this->scopeQueryToCurrentStore(Sale::where('date', $today))->sum('shipping');
        $totalGrandTotalAmount = $this->scopeQueryToCurrentStore(Sale::where('date', $today))->sum('grand_total');

        $data['today_sales_cash_payment'] = $this->scopeSalesPaymentsToCurrentStore(SalesPayment::where('payment_date', $today)->where(
            'payment_type',
            SalesPayment::CASH
        ))->sum('amount');
        $data['today_sales_cheque_payment'] = $this->scopeSalesPaymentsToCurrentStore(SalesPayment::where('payment_date', $today)->where(
            'payment_type',
            SalesPayment::CHEQUE
        ))->sum('amount');
        $data['today_sales_bank_transfer_payment'] = $this->scopeSalesPaymentsToCurrentStore(SalesPayment::where('payment_date', $today)->where(
            'payment_type',
            SalesPayment::BANK_TRANSFER
        ))->sum('amount');
        $data['today_sales_other_payment'] = $this->scopeSalesPaymentsToCurrentStore(SalesPayment::where('payment_date', $today)->where(
            'payment_type',
            SalesPayment::OTHER
        ))->sum('amount');

        $data['today_sales_total_amount'] = $totalGrandTotalAmount;
        $data['today_sales_total_return_amount'] = $this->scopeQueryToCurrentStore(SaleReturn::where('date', $today))->sum('grand_total');
        $data['today_sales_payment_amount'] = $this->scopeSalesPaymentsToCurrentStore(SalesPayment::where('payment_date', $today))->sum('amount');

        $productsData = Product::leftJoin(
            'sale_items',
            'products.id',
            '=',
            'sale_items.product_id'
        )
            ->whereBetween('sale_items.created_at', [$todayStartUtc, $todayEndUtc])
            ->when($storeId, function ($q) use ($storeId) {
                $q->where('products.store_id', $storeId);
            })
            ->selectRaw('products.*, COALESCE(sum(sale_items.sub_total),0) grand_total')
            ->selectRaw('products.*, COALESCE(sum(sale_items.quantity),0) total_quantity')
            ->groupBy('products.id')
            ->get();

        $productsSold = [];
        $data['all_grand_total_amount'] = 0;

        foreach ($productsData as $key => $product) {
            $productsSold[] = $product->prepareProductReport();
            $data['all_grand_total_amount'] = $data['all_grand_total_amount'] + $product->grand_total;
        }
        $data['today_total_products_sold'] = $productsSold;

        $data['today_brand_report'] = Brand::leftJoin(
            'products',
            'brands.id',
            '=',
            'products.brand_id'
        )->leftJoin(
            'sale_items',
            'products.id',
            '=',
            'sale_items.product_id'
        )
            ->whereBetween('sale_items.created_at', [$todayStartUtc, $todayEndUtc])
            ->when($storeId, function ($q) use ($storeId) {
                $q->where('brands.store_id', $storeId);
            })
            ->selectRaw('brands.*, COALESCE(sum(sale_items.sub_total),0) grand_total')
            ->selectRaw('brands.*, COALESCE(sum(sale_items.quantity),0) total_quantity')
            ->groupBy('brands.id')
            ->get();

        $data['all_tax_amount'] = $salesTax;
        $data['all_discount_amount'] = $salesDiscount;
        $data['all_shipping_amount'] = $salesShippingAmount;
        $data['all_grand_total_amount'] = $totalGrandTotalAmount;

        $cashInHand = 0;
        $register = POSRegister::openForUser((int) Auth::id())
            ->forStore($this->currentStoreId())
            ->latest()->first();
        if ($register) {
            $cashInHand = $register->cash_in_hand;
        }

        $data['cash_in_hand'] = $cashInHand;
        $data['total_cash_amount'] = $cashInHand + $data['today_sales_cash_payment'];

        return $this->sendResponse($data, 'Today sales register overall report retrieved successfully');
    }

    public function getSupplierReport(Request $request): JsonResponse
    {
        $perPage = getPageSize($request);
        $suppliersQuery = $this->supplierRepository->withCount('purchases')->with('purchases');
        if ($storeId = $this->currentStoreId()) {
            $suppliersQuery = $suppliersQuery->where('store_id', $storeId);
        }
        $suppliers = $suppliersQuery->paginate($perPage);

        foreach ($suppliers as $key => $supplier) {
            $suppliers[$key]['total_grand_amount'] = $supplier->purchases->sum('grand_total');
        }

        return $this->sendResponse($suppliers, 'Suppliers  retrieved successfully');
    }

    public function getSupplierPurchasesReport($supplierId, Request $request): JsonResponse
    {
        $perPage = getPageSize($request);

        $this->authorizeStoreOwnership(Supplier::findOrFail($supplierId));

        $search = $request->filter['search'] ?? '';
        $supplier = (Supplier::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $warehouse = (Warehouse::active()->where('name', 'LIKE', "%$search%")->get()->count() != 0);

        $purchases = QueryBuilder::for(Purchase::class)
            ->where('supplier_id', $supplierId)
            ->search($search)
            ->allowedSorts('reference_code', 'created_at')
            ->allowedFilters(['reference_code'])
            ->with('warehouse', 'supplier');

        $this->scopeQueryToCurrentStore($purchases);

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

        $purchases = $purchases->paginate($perPage);

        return $this->sendResponse($purchases, 'Supplier purchases retrieved successfully');
    }

    public function getSupplierPurchasesReturnReport($supplierId, Request $request)
    {
        $perPage = getPageSize($request);

        $this->authorizeStoreOwnership(Supplier::findOrFail($supplierId));

        $search = $request->filter['search'] ?? '';
        $supplier = (Supplier::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $warehouse = (Warehouse::active()->where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $reference = (PurchaseReturn::whereSupplierId($supplierId)->where(
            'reference_code',
            'LIKE',
            "%$search%"
        )->get()->count() != 0);

        $purchaseReturns = QueryBuilder::for(PurchaseReturn::class)
            ->where('supplier_id', $supplierId)
            ->with('warehouse', 'supplier');

        $this->scopeQueryToCurrentStore($purchaseReturns);

        if ($supplier || $warehouse) {
            $purchaseReturns->whereHas('supplier', function (Builder $q) use ($search, $supplier) {
                if ($supplier) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            })->whereHas('warehouse', function (Builder $q) use ($search, $warehouse) {
                if ($warehouse) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            });
        }

        if ($reference) {
            $purchaseReturns->where('reference_code', 'LIKE', "%$search%");
        }

        $purchaseReturns = $purchaseReturns->paginate($perPage);

        return $this->sendResponse($purchaseReturns, 'Supplier purchase returns retrieved successfully');
    }

    public function getSupplierInfo($supplierId)
    {
        $this->authorizeStoreOwnership(Supplier::findOrFail($supplierId));

        $data = [];
        $purchases = $this->scopeQueryToCurrentStore($this->purchaseRepository->whereSupplierId($supplierId));
        $purchaseReturns = $this->scopeQueryToCurrentStore($this->purchaseReturnRepository->whereSupplierId($supplierId));

        $data['purchases_count'] = $purchases->count();
        $data['purchases_total_amount'] = $purchases->sum('grand_total');
        $data['purchases_returns_count'] = $purchaseReturns->count();
        $data['purchases_returns_total_amount'] = $purchaseReturns->sum('grand_total');

        return $this->sendResponse($data, 'Supplier info retrieved successfully');
    }

    public function getBestCustomersReport(Request $request): JsonResponse
    {
        $month = Carbon::now('America/Guayaquil')->month;
        $topCustomers = Customer::leftJoin('sales', 'customers.id', '=', 'sales.customer_id')
            ->whereMonth('date', $month)
            ->when($this->currentStoreId(), function ($q, $storeId) {
                $q->where('customers.store_id', $storeId);
            })
            ->select('customers.*', DB::raw('sum(sales.grand_total) as grand_total'))
            ->groupBy('customers.id')
            ->orderBy('grand_total', 'desc')
            ->latest()
            ->take(5)
            ->withCount('sales')
            ->get();

        $totalRecords = $topCustomers->count();

        return Response::json([
            'success' => true,
            'total_records' => $totalRecords,
            'top_customers' => $topCustomers,
            'message' => 'Best Customers report Retrieved Successfully',
        ]);
    }

    public function getProfitLossReport(Request $request)
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);
        if (! empty($validated['warehouse_id'])) {
            $this->authorizeWarehouseAccess((int) $validated['warehouse_id']);
        }

        return $this->sendResponse(
            app(ProfitLossReportService::class)->generate(
                $validated['start_date'],
                $validated['end_date'],
                $this->currentStoreId(),
                isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null
            ),
            'Profit loss report info retrieved successfully'
        );

    }

    public function getCustomerReport(Request $request)
    {
        $perPage = getPageSize($request);
        $customersQuery = $this->customerRepository->withCount('sales')->with('sales.payments');
        if ($storeId = $this->currentStoreId()) {
            $customersQuery = $customersQuery->where('store_id', $storeId);
        }
        $customers = $customersQuery->paginate($perPage);

        foreach ($customers as $key => $customer) {
            $totalPaidAmount = 0;
            $grandTotalAmount = $customer->sales->sum('grand_total');
            $customers[$key]['total_grand_amount'] = $grandTotalAmount;
            foreach ($customer->sales as $sale) {
                $totalPaidAmount = $totalPaidAmount + $sale->payments->sum('amount');
            }
            $totalDueAmount = $grandTotalAmount - $totalPaidAmount;
            $customers[$key]['total_paid_amount'] = $totalPaidAmount;
            $customers[$key]['total_due_amount'] = $totalDueAmount;
        }

        return $this->sendResponse($customers, 'Customers all report retrieved successfully');
    }

    public function getCustomerPaymentsReport($id, Request $request): JsonResponse
    {
        $perPage = getPageSize($request);

        $customer = Customer::findOrFail($id);
        $this->authorizeStoreOwnership($customer);

        $saleIds = [];

        $sales = Sale::whereCustomerId($id)->get();

        foreach ($sales as $sale) {
            $saleIds[] = $sale->id;
        }

        $payments = QueryBuilder::for(SalesPayment::class)
            ->whereIn('sale_id', $saleIds)
            ->with('sale')
            ->paginate($perPage);

        return $this->sendResponse($payments, 'Customers payments report retrieved successfully');
    }

    public function getCustomerInfo(Customer $customer)
    {
        $this->authorizeStoreOwnership($customer);

        $salesData = [];

        $salesData['totalSale'] = $customer->sales->count();

        $salesData['totalAmount'] = $customer->sales->sum('grand_total');

        $salesData['totalPaid'] = 0;

        foreach ($customer->sales as $sale) {
            $salesData['totalPaid'] = $salesData['totalPaid'] + $sale->payments->sum('amount');
        }

        $salesData['totalSalesDue'] = $salesData['totalAmount'] - $salesData['totalPaid'];

        return $this->sendResponse($salesData, 'Customer info retrieved successfully');
    }
}
