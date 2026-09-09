<?php

use App\Http\Controllers\API\AdjustmentAPIController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\BackupController;
use App\Http\Controllers\API\BaseUnitAPIController;
use App\Http\Controllers\API\BrandAPIController;
use App\Http\Controllers\API\CouponCodeAPIController;
use App\Http\Controllers\API\CurrencyAPIController;
use App\Http\Controllers\API\CustomerAPIController;
use App\Http\Controllers\API\DashboardAPIController;
use App\Http\Controllers\API\ExpenseAPIController;
use App\Http\Controllers\API\ExpenseCategoryAPIController;
use App\Http\Controllers\API\HoldAPIController;
use App\Http\Controllers\API\HealthController;
use App\Http\Controllers\API\OfflineCustomerSyncController;
use App\Http\Controllers\API\OfflineSaleSyncController;
use App\Http\Controllers\API\OfflineSyncTokenController;
use App\Http\Controllers\API\LanguageAPIController;
use App\Http\Controllers\API\MainProductAPIController;
use App\Http\Controllers\API\ManageStockAPIController;
use App\Http\Controllers\API\PermissionController;
use App\Http\Controllers\API\POSRegisterAPIController;
use App\Http\Controllers\API\CashControlAPIController;
use App\Http\Controllers\API\AccountsReceivableAPIController;
use App\Http\Controllers\API\ProductAPIController;
use App\Http\Controllers\API\ProductCategoryAPIController;
use App\Http\Controllers\API\PurchaseAPIController;
use App\Http\Controllers\API\PurchaseReturnAPIController;
use App\Http\Controllers\API\QuotationAPIController;
use App\Http\Controllers\API\ReportAPIController;
use App\Http\Controllers\API\RoleAPIController;
use App\Http\Controllers\API\SaleAPIController;
use App\Http\Controllers\API\SaleReturnAPIController;
use App\Http\Controllers\API\CreditNoteAPIController;
use App\Http\Controllers\API\CreditNoteCategoryAPIController;
use App\Http\Controllers\API\SalesPaymentAPIController;
use App\Http\Controllers\API\SettingAPIController;
use App\Http\Controllers\API\SmsSettingAPIController;
use App\Http\Controllers\API\SmsTemplateAPIController;
use App\Http\Controllers\API\SriController;
use App\Http\Controllers\API\SriConfigController;
use App\Http\Controllers\API\StoreAPIController;
use App\Http\Controllers\API\OrganizationAPIController;
use App\Http\Controllers\API\SaaSOnboardingController;
use App\Http\Controllers\API\SaaSSuperAdminController;
use App\Http\Controllers\API\SuperAdminSecurityController;
use App\Http\Controllers\API\SaaSSubscriptionPortalController;
use App\Http\Controllers\API\LandingPageSettingController;
use App\Http\Controllers\API\CatalogSettingAPIController;
use App\Http\Controllers\API\CatalogOrderAPIController;
use App\Http\Controllers\API\PublicCatalogController;
use App\Http\Controllers\API\ElectronicInvoiceController;
use App\Http\Controllers\API\SupplierAPIController;
use App\Http\Controllers\API\TransferAPIController;
use App\Http\Controllers\API\TenantFileDownloadController;
use App\Http\Controllers\API\UnitAPIController;
use App\Http\Controllers\API\UserAPIController;
use App\Http\Controllers\API\WarehouseAPIController;
use App\Http\Controllers\API\VariationAPIController;
use App\Http\Controllers\API\PresentationCatalogAPIController;
use App\Http\Controllers\API\KardexAPIController;
use App\Http\Controllers\API\InventoryCountAPIController;
use App\Http\Controllers\API\LoginLogController;
use App\Http\Controllers\MailTemplateAPIController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

//Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//    return $request->user();
//});

// Comprobación mínima usada por el POS para distinguir entre una tarjeta
// de red activa y una conexión que realmente alcanza EcuaPos. No consulta
// base de datos ni expone información de la instalación.
Route::get('/health', HealthController::class);

Route::middleware(['auth:sanctum', 'super.admin'])->prefix('super-admin')->group(function () {
    Route::get('security/two-factor', [SuperAdminSecurityController::class, 'status']);
    Route::post('security/two-factor/setup', [SuperAdminSecurityController::class, 'setup']);
    Route::post('security/two-factor/confirm', [SuperAdminSecurityController::class, 'confirm']);
    Route::delete('security/two-factor', [SuperAdminSecurityController::class, 'disable']);
});

Route::middleware(['auth:sanctum', 'super.admin', 'super.admin.2fa'])->prefix('super-admin')->group(function () {
    Route::get('backup/download', [BackupController::class, 'download']);
    Route::post('cache-clear', [SettingAPIController::class, 'clearCache']);
    Route::resource('currencies', CurrencyAPIController::class)->except(['index'])->names([
        'create' => 'super-admin.currencies.create',
        'store' => 'super-admin.currencies.store',
        'show' => 'super-admin.currencies.show',
        'edit' => 'super-admin.currencies.edit',
        'update' => 'super-admin.currencies.update',
        'destroy' => 'super-admin.currencies.destroy',
    ]);
    Route::resource('languages', LanguageAPIController::class)->except(['index'])->names([
        'create' => 'super-admin.languages.create',
        'store' => 'super-admin.languages.store',
        'show' => 'super-admin.languages.show',
        'edit' => 'super-admin.languages.edit',
        'update' => 'super-admin.languages.update',
        'destroy' => 'super-admin.languages.destroy',
    ]);
    Route::get('languages/translation/{language}', [LanguageAPIController::class, 'showTranslation']);
    Route::post('languages/translation/{language}/update', [LanguageAPIController::class, 'updateTranslation']);
    Route::get('dashboard', [SaaSSuperAdminController::class, 'dashboard']);
    Route::get('organizations', [SaaSSuperAdminController::class, 'organizations']);
    Route::patch('organizations/{organization}', [SaaSSuperAdminController::class, 'updateOrganization']);
    Route::get('users', [SaaSSuperAdminController::class, 'users']);
    Route::get('plans', [SaaSSuperAdminController::class, 'plans']);
    Route::post('plans', [SaaSSuperAdminController::class, 'storePlan']);
    Route::put('plans/{plan}', [SaaSSuperAdminController::class, 'updatePlan']);
    Route::get('subscriptions', [SaaSSuperAdminController::class, 'subscriptions']);
    Route::post('organizations/{organization}/subscription', [SaaSSuperAdminController::class, 'assignPlan']);
    Route::patch('subscriptions/{subscription}', [SaaSSuperAdminController::class, 'updateSubscription']);
    Route::post('subscriptions/{subscription}/cancel', [SaaSSuperAdminController::class, 'cancelSubscription']);
    Route::get('payments', [SaaSSuperAdminController::class, 'payments']);
    Route::get('payments/{payment}/proof', [SaaSSuperAdminController::class, 'paymentProof']);
    Route::post('subscriptions/{subscription}/payments', [SaaSSuperAdminController::class, 'recordPayment']);
    Route::post('payments/{payment}/approve', [SaaSSuperAdminController::class, 'approvePayment']);
    Route::post('payments/{payment}/reject', [SaaSSuperAdminController::class, 'rejectPayment']);
    Route::get('landing-page', [LandingPageSettingController::class, 'show']);
    Route::put('landing-page', [LandingPageSettingController::class, 'update']);
    Route::post('landing-page/upload', [LandingPageSettingController::class, 'upload']);
});

Route::middleware('auth:sanctum')->prefix('subscription-portal')->group(function () {
    Route::get('/', [SaaSSubscriptionPortalController::class, 'show']);
    Route::post('/payments', [SaaSSubscriptionPortalController::class, 'submitPayment']);
    Route::post('/cancel', [SaaSSubscriptionPortalController::class, 'cancel']);
});

// Acciones de seguridad y cierre de sesión deben seguir disponibles aunque
// la organización esté vencida o suspendida.
Route::middleware('auth:sanctum')->post('logout', [AuthController::class, 'logout']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('edit-profile', [UserAPIController::class, 'editProfile'])->name('edit-profile');
    Route::post('update-profile', [UserAPIController::class, 'updateProfile'])->name('update-profile');
    Route::patch('change-password', [UserAPIController::class, 'changePassword'])->name('user.changePassword');
    Route::post('change-language', [UserAPIController::class, 'updateLanguage']);
});
Route::middleware(['auth:sanctum', 'abilities:*'])
    ->delete('offline-sync/device-token', [OfflineSyncTokenController::class, 'destroy']);

Route::get('/sri/lookup', [SriController::class, 'lookup']);
Route::prefix('catalog/{store:slug}')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [PublicCatalogController::class, 'show']);
});
Route::middleware(['auth:sanctum', 'store.context', 'subscription.active'])->group(function () {
    Route::get('tenant-files/{category}/{filename}', TenantFileDownloadController::class)
        ->where(['category' => 'excel|pdf', 'filename' => '[A-Za-z0-9][A-Za-z0-9._-]*']);
    Route::middleware(['abilities:*', 'permission:manage_sale|manage_pos_screen'])->prefix('offline-sync')->group(function () {
        Route::post('device-token', [OfflineSyncTokenController::class, 'store']);
    });

    // ── Facturación electrónica (SRI) ──────────────────────────────
    Route::prefix('electronic-invoices')->middleware('permission:manage_electronic_invoices')->group(function () {
        Route::get('/', [ElectronicInvoiceController::class, 'index']);
        Route::get('/{electronicInvoice}', [ElectronicInvoiceController::class, 'show']);
        Route::get('/{electronicInvoice}/ruta', [ElectronicInvoiceController::class, 'ruta']);
        Route::get('/{electronicInvoice}/ride', [ElectronicInvoiceController::class, 'ride']);
        Route::get('/{electronicInvoice}/xml', [ElectronicInvoiceController::class, 'descargarXml']);
    });

    Route::prefix('sri-config')->middleware('permission:manage_sri_config')->group(function () {
        Route::get('/', [SriConfigController::class, 'index']);
        Route::post('/certificado', [SriConfigController::class, 'subirCertificado']);
        Route::post('/guardar', [SriConfigController::class, 'guardarConfig']);
        Route::get('/verificar-certificado', [SriConfigController::class, 'verificarCertificado']);
        Route::post('/logo', [SriConfigController::class, 'subirLogo']);
        Route::delete('/logo', [SriConfigController::class, 'eliminarLogo']);
        Route::get('/sequences', [SriConfigController::class, 'sequences']);
        Route::put('/sequences/{documentType}', [SriConfigController::class, 'updateSequence']);
    });

    Route::prefix('sales/{sale}/electronic-invoice')->middleware('permission:manage_electronic_invoices')->group(function () {
        Route::post('/emitir', [ElectronicInvoiceController::class, 'emitir']);
        Route::get('/estado', [ElectronicInvoiceController::class, 'estado']);
        Route::post('/reintentar', [ElectronicInvoiceController::class, 'reintentar']);
    });

    Route::middleware('permission:manage_brands')->group(function () {
        Route::post('/brands', [BrandAPIController::class, 'store']);
        Route::get('/brands/{id}', [BrandAPIController::class, 'show'])->name('brands.show');
        Route::post('/brands/{id}', [BrandAPIController::class, 'update']);
        Route::delete('/brands/{brand}', [BrandAPIController::class, 'destroy']);
    });
    Route::get('/brands', [BrandAPIController::class, 'index']);
    //Dashboard
    Route::middleware('permission:manage_dashboard')->group(function () {
        Route::get('today-sales-purchases-count', [DashboardAPIController::class, 'getPurchaseSalesCounts']);
        Route::get('all-sales-purchases-count', [DashboardAPIController::class, 'getAllPurchaseSalesCounts']);
        Route::get('recent-sales', [DashboardAPIController::class, 'getRecentSales']);
        Route::get('top-selling-products', [DashboardAPIController::class, 'getTopSellingProducts']);
        Route::get('week-selling-purchases', [DashboardAPIController::class, 'getWeekSalePurchases']);
        Route::get('yearly-top-selling', [DashboardAPIController::class, 'getYearlyTopSelling']);
        Route::get('top-customers', [DashboardAPIController::class, 'getTopCustomer']);
        Route::get('stock-alerts', [DashboardAPIController::class, 'stockAlerts']);
        Route::get('dashboard/today-overview', [DashboardAPIController::class, 'getTodayOverview']);
        Route::get('dashboard/today-hourly-breakdown', [DashboardAPIController::class, 'getTodayHourlyBreakdown']);
        Route::get('dashboard/performance-net-sales', [DashboardAPIController::class, 'getPerformanceNetSales']);
        Route::get('dashboard/category-mix', [DashboardAPIController::class, 'getCategoryMix']);
        Route::get('dashboard/top-products', [DashboardAPIController::class, 'getTopProducts']);
        Route::get('dashboard/sales-heatmap', [DashboardAPIController::class, 'getSalesHeatmap']);
        Route::get('dashboard/active-shifts', [DashboardAPIController::class, 'getActiveShifts']);
    });

    // get all permission
    Route::get('/permissions', [PermissionController::class, 'getPermissions'])->name('get-permissions');

    // roles route
    Route::middleware('permission:manage_roles')->group(function () {
        Route::resource('roles', RoleAPIController::class)->except(['index']);
    });
    Route::get('roles', [RoleAPIController::class, 'index']);

    // product category route
    Route::middleware('permission:manage_product_categories')->group(function () {
        Route::resource('product-categories', ProductCategoryAPIController::class)->except(['index']);
        Route::post(
            'product-categories/{product_category}',
            [ProductCategoryAPIController::class, 'update']
        )->name('product-category');
    });

    Route::get('product-categories', [ProductCategoryAPIController::class, 'index']);

    // Monedas e idiomas son catálogos de plataforma, no datos de una
    // organización. Los tenants pueden consultarlos y elegirlos en sus
    // ajustes, pero sus mutaciones viven exclusivamente en super-admin.
    Route::get('currencies', [CurrencyAPIController::class, 'index']);
    Route::get('currencies/{currency}', [CurrencyAPIController::class, 'show'])->name('currencies.show');

    // warehouses route
    Route::middleware('permission:manage_warehouses')->group(function () {
        Route::resource('warehouses', WarehouseAPIController::class)->except(['index']);
        Route::get('warehouse-details/{id}', [WarehouseAPIController::class, 'warehouseDetails']);
    });
    Route::get('warehouses', [WarehouseAPIController::class, 'index']);

    // stores route (CRUD del catálogo de tiendas -- distinto de my-stores,
    // que cualquier usuario autenticado puede leer para el selector)
    Route::middleware('permission:manage_stores')->group(function () {
        Route::resource('stores', StoreAPIController::class);
        Route::get('catalog-settings', [CatalogSettingAPIController::class, 'show']);
        Route::put('catalog-settings', [CatalogSettingAPIController::class, 'update']);
    });

    Route::prefix('catalog-orders')->middleware('permission:manage_catalog_orders')->group(function () {
        Route::get('/', [CatalogOrderAPIController::class, 'index']);
        Route::get('/{catalogOrder}', [CatalogOrderAPIController::class, 'show']);
        Route::patch('/{catalogOrder}/status', [CatalogOrderAPIController::class, 'updateStatus']);
        Route::patch('/{catalogOrder}/notes', [CatalogOrderAPIController::class, 'updateNotes']);
        Route::post('/{catalogOrder}/convert-to-sale', [CatalogOrderAPIController::class, 'convertToSale']);
    });

    // units route
    Route::middleware('permission:manage_units')->group(function () {
        Route::resource('units', UnitAPIController::class)->except(['index']);
        Route::resource('base-units', BaseUnitAPIController::class);
    });
    Route::get('units', [UnitAPIController::class, 'index']);

    // products route

    // Sin este grupo, products/main-products/variations/purchases/
    // purchases-return no tenían NINGÚN chequeo de permiso -- ni acá ni
    // en el authorize() de sus FormRequest (todos devuelven `true` a
    // secas). Cualquier usuario autenticado, sin importar su rol/
    // permisos reales, podía crear/editar/borrar productos, compras,
    // etc. por API directa -- el candado del menú lateral era solo
    // decorativo para estos módulos. index/show se dejan abiertos (se
    // usan para lectura desde el POS -- ver posFetchProduct() -- y
    // desde reportes/otras pantallas que no deberían necesitar el
    // permiso de administración del catálogo solo para consultar).
    Route::middleware('permission:manage_products')->group(function () {
        Route::post('main-products/bulk-delete', [MainProductAPIController::class, 'bulkDestroy']);
        Route::resource('products', ProductAPIController::class)->only(['store', 'update', 'destroy']);
        Route::resource('main-products', MainProductAPIController::class)->only(['store', 'update', 'destroy']);
        Route::post(
            'products/{product}',
            [ProductAPIController::class, 'update']
        );
        Route::post(
            'main-products/{product}',
            [MainProductAPIController::class, 'update']
        );
        Route::delete(
            'products-image-delete/{mediaId}',
            [ProductAPIController::class, 'productImageDelete']
        )->name('products-image-delete');
    });

    Route::get('products', [ProductAPIController::class, 'index'])->name('products.index');
    // Product::prepareLinks()/MainProduct::prepareLinks() generan su
    // 'self' link llamando a route('products.show', ...) -- sin el
    // ->name() acá, ese route() revienta con "Route [products.show] not
    // defined." apenas se pide CUALQUIER producto (index incluido, ya
    // que arma el link por cada fila). Encontrado recién al probar el
    // fix en vivo.
    Route::get('products/{product}', [ProductAPIController::class, 'show'])->name('products.show');
    Route::get('main-products', [MainProductAPIController::class, 'index'])->name('main-products.index');
    Route::get('main-products/{product}', [MainProductAPIController::class, 'show']);
    Route::get('get-all-products', [ProductAPIController::class, 'getAllProducts']);

    Route::get('product-presentations', [\App\Http\Controllers\API\ProductPresentationAPIController::class, 'index']);
    Route::middleware('permission:manage_products')->group(function () {
        Route::resource('product-presentations', \App\Http\Controllers\API\ProductPresentationAPIController::class)
            ->only(['store', 'update', 'destroy']);
    });
    Route::get('presentation-catalog', [PresentationCatalogAPIController::class, 'index']);
    Route::middleware('permission:manage_products|manage_variations')->group(function () {
        Route::post('presentation-catalog/families', [PresentationCatalogAPIController::class, 'storeFamily']);
        Route::post('presentation-catalog/families/{family}/types', [PresentationCatalogAPIController::class, 'storeType']);
    });

    Route::get('product-kits', [\App\Http\Controllers\API\ProductKitAPIController::class, 'index']);
    Route::middleware('permission:manage_products')->group(function () {
        Route::resource('product-kits', \App\Http\Controllers\API\ProductKitAPIController::class)
            ->only(['store', 'update', 'destroy']);
        // PUT con multipart/form-data no llega bien a $_FILES en PHP
        // -- necesario para editar el kit y su imagen juntos.
        Route::post('product-kits/{product_kit}', [\App\Http\Controllers\API\ProductKitAPIController::class, 'update']);
    });

    Route::get('products/{product}/warehouse-prices', [\App\Http\Controllers\API\WarehousePriceAPIController::class, 'forProduct']);
    Route::get('product-presentations/{presentation}/warehouse-prices', [\App\Http\Controllers\API\WarehousePriceAPIController::class, 'forPresentation']);
    Route::middleware('permission:manage_products')->group(function () {
        Route::put('products/{product}/warehouse-prices', [\App\Http\Controllers\API\WarehousePriceAPIController::class, 'updateForProduct']);
        Route::put('product-presentations/{presentation}/warehouse-prices', [\App\Http\Controllers\API\WarehousePriceAPIController::class, 'updateForPresentation']);
        Route::post('import-products', [ProductAPIController::class, 'importProducts']);
        Route::get(
            'products-export-excel/{id?}',
            [ProductAPIController::class, 'getProductExportExcel']
        )->name('products-export-excel');
    });

    Route::middleware('permission:manage_variations')->group(function () {
        Route::resource('variations', VariationAPIController::class)->only(['store', 'update', 'destroy']);
    });
    Route::get('variations', [VariationAPIController::class, 'index']);
    Route::get('variations/{variation}', [VariationAPIController::class, 'show'])->name('variations.show');

    Route::middleware('permission:manage_transfers')->group(function () {
        Route::resource('transfers', TransferAPIController::class);
    });

    // customers route
    Route::middleware('permission:manage_customers')->group(function () {
        Route::resource('customers', CustomerAPIController::class)->except(['index', 'store']);
        Route::post('import-customers', [CustomerAPIController::class, 'importCustomers']);
    });
    Route::middleware('permission:change_customer_passwords')->group(function () {
        Route::post('customers/{customer}/change-password', [CustomerAPIController::class, 'updatePassword']);
    });
    // El modal "Agregar cliente" del propio POS (CustomerForm.js dentro de
    // frontend/, distinto del formulario admin de Personas > Clientes)
    // llama esta misma ruta -- mismo caso que sales.store: un vendedor sin
    // manage_customers (a propósito, ver Roles/Permisos) recibía "User dose
    // not have the right permission" al intentar registrar un cliente
    // nuevo a mitad de una venta. store_id se fuerza server-side en
    // CustomerAPIController::store(), sin riesgo de escalar a otra tienda.
    Route::middleware('permission:manage_customers|manage_pos_screen')->group(function () {
        Route::post('customers', [CustomerAPIController::class, 'store'])->name('customers.store');
    });

    Route::get('customers', [CustomerAPIController::class, 'index']);

    //Users route
    Route::middleware('permission:manage_users')->group(function () {
        Route::resource('users', UserAPIController::class);
        Route::post('users/{user}', [UserAPIController::class, 'update']);
    });
    Route::post('users/{user}/change-password', [UserAPIController::class, 'updateUserPassword'])
        ->middleware('permission:change_user_passwords');
    Route::get('kardex', [KardexAPIController::class, 'index'])
        ->middleware('permission:manage_kardex');
    Route::middleware('permission:manage_login_logs')->group(function () {
        Route::get('ip-location/{ip}', [LoginLogController::class, 'getIpLocation']);
        Route::get('login-logs', [LoginLogController::class, 'index']);
        Route::delete('login-logs/bulk-delete', [LoginLogController::class, 'bulkDestroy']);
        Route::delete('login-logs/{id}', [LoginLogController::class, 'destroy']);
    });

    //suppliers route
    Route::middleware('permission:manage_suppliers')->group(function () {
        Route::resource('suppliers', SupplierAPIController::class)->except(['index']);
        Route::post('import-suppliers', [SupplierAPIController::class, 'importSuppliers']);
    });
    Route::get('suppliers', [SupplierAPIController::class, 'index']);

    //sale
    Route::middleware('permission:manage_sale')->group(function () {
        Route::resource('sales', SaleAPIController::class)->except(['index', 'store']);
        Route::get('sale-pdf-download/{sale}', [SaleAPIController::class, 'pdfDownload'])->name('sale-pdf-download');
        Route::get('sale-info/{sale}', [SaleAPIController::class, 'saleInfo'])->name('sale-info');

        Route::post('sales/{sale}/capture-payment', [SalesPaymentAPIController::class, 'createSalePayment']);
        Route::get('sales/{sale}/payments', [SalesPaymentAPIController::class, 'getAllPayments']);
        Route::post('sales/{salesPayment}/payment', [SalesPaymentAPIController::class, 'updateSalePayment']);
        Route::delete('sales/{id}/payment', [SalesPaymentAPIController::class, 'deletePayment']);
    });
    // El checkout del POS (posCashPaymentAction.js) pega contra esta misma
    // ruta (apiBaseURL.CASH_PAYMENT = "sales") -- con manage_sale como
    // único permiso aceptado, el rol Vendedor (manage_pos_screen +
    // manage_my-sales, sin manage_sale a propósito) recibía "User dose
    // not have the right permission" al intentar vender, en CUALQUIER
    // tienda. Seguro ampliar: store() ya fuerza user_id=Auth::id() y ya
    // llama authorizeWarehouseAccess() (bloquea vender en un almacén de
    // otra sucursal/tienda), sin importar qué permiso haya dejado pasar
    // el middleware.
    Route::middleware('permission:manage_sale|manage_pos_screen')->group(function () {
        Route::post('sales', [SaleAPIController::class, 'store'])->name('sales.store');
    });
    // "Mis Ventas" (SellerDashboard.js) llama este mismo index() filtrado
    // por su propio user_id -- con manage_sale como único permiso
    // aceptado, un vendedor con solo manage_my-sales (sin manage_sale a
    // propósito, ver Roles/Permisos) recibía 403 apenas entraba a esa
    // pantalla. SaleAPIController::index() fuerza server-side el
    // user_id a Auth::id() cuando falta manage_sale, así que ampliar
    // acá no deja ver las ventas de otros vendedores.
    Route::middleware('permission:manage_sale|manage_my-sales')->group(function () {
        Route::get('sales', [SaleAPIController::class, 'index'])->name('sales.index');
    });

    Route::resource('holds', HoldAPIController::class)
        ->middleware('permission:manage_sale|manage_pos_screen');

    // Quotation
    Route::middleware('permission:manage_quotations')->group(function () {
        Route::resource('quotations', QuotationAPIController::class);
        Route::get('quotation-info/{quotation}', [QuotationAPIController::class, 'quotationInfo']);
        Route::get('quotation-pdf-download/{quotation}', [QuotationAPIController::class, 'pdfDownload']);
    });

    Route::middleware('permission:manage_email_templates')->group(function () {
        Route::resource('mail-templates', MailTemplateAPIController::class);
        Route::post('mail-template-status/{id}', [MailTemplateAPIController::class, 'changeActiveStatus']);
    });

    Route::middleware('permission:manage_sms_templates')->group(function () {
        Route::resource('sms-templates', SmsTemplateAPIController::class);
        Route::post('sms-template-status/{id}', [SmsTemplateAPIController::class, 'changeActiveStatus']);
    });

    //sale return
    Route::middleware('permission:manage_sale_return')->group(function () {
        Route::resource('sales-return', SaleReturnAPIController::class);
        Route::get('sales-return-edit/{id}', [SaleReturnAPIController::class, 'editBySale']);
        Route::get(
            'sale-return-info/{sales_return}',
            [SaleReturnAPIController::class, 'saleReturnInfo']
        )->name('sale-return-info');
        Route::get(
            'sale-return-pdf-download/{sale_return}',
            [SaleReturnAPIController::class, 'pdfDownload']
        )->name('sale-return-pdf-download');

        // credit notes
        Route::get('credit-notes/buscar-factura', [CreditNoteAPIController::class, 'buscarFactura']);
        Route::get('credit-notes/facturas-cliente/{customer}', [CreditNoteAPIController::class, 'facturasDeCliente']);
        Route::post('credit-notes/{credit_note}/emitir', [CreditNoteAPIController::class, 'emitir']);
        Route::post('credit-notes/{credit_note}/cancelar', [CreditNoteAPIController::class, 'cancelar']);
        Route::resource('credit-notes', CreditNoteAPIController::class)->only(['index', 'store', 'show']);
        Route::resource('credit-note-categories', CreditNoteCategoryAPIController::class)->only(['index', 'store']);
    });

    //expense category route
    Route::middleware('permission:manage_expense_categories')->group(function () {
        Route::resource('expense-categories', ExpenseCategoryAPIController::class)->except(['index']);
    });
    Route::get('expense-categories', [ExpenseCategoryAPIController::class, 'index']);

    //expense route
    Route::middleware('permission:manage_expenses')->group(function () {
        Route::resource('expenses', ExpenseAPIController::class);
    });

    //setting route
    Route::middleware('permission:manage_setting')->group(function () {
        Route::resource('settings', SettingAPIController::class)->except(['index']);
        Route::post('settings', [SettingAPIController::class, 'update']);
        Route::get('states/{id}', [SettingAPIController::class, 'getStates']);
        Route::get('mail-settings', [SettingAPIController::class, 'getMailSettings']);
        Route::post('mail-settings/update', [SettingAPIController::class, 'updateMailSettings']);
    });

    // El listado de idiomas alimenta el selector de idioma del navbar,
    // visible para cualquier usuario logueado (no solo quien administra
    // traducciones) -- por eso index() queda fuera del permiso
    // manage_language, igual que el patrón ya usado arriba con
    // settings->except(['index']). Crear/editar idiomas y traducciones
    // es una operación global y queda fuera del panel tenant.
    Route::get('languages', [LanguageAPIController::class, 'index']);
    Route::get('languages/translation/{language}', [LanguageAPIController::class, 'showTranslation']);
    Route::get('languages/{language}', [LanguageAPIController::class, 'show'])->name('languages.show');

    Route::middleware('permission:manage_sms_apis')->group(function () {
        Route::resource('sms-settings', SmsSettingAPIController::class);
        Route::post('sms-settings', [SmsSettingAPIController::class, 'update']);
    });

    Route::get('settings', [SettingAPIController::class, 'index']);

    //purchase routes
    Route::middleware('permission:manage_purchase')->group(function () {
        Route::resource('purchases', PurchaseAPIController::class)->only(['store', 'update', 'destroy']);
    });
    Route::get('purchases', [PurchaseAPIController::class, 'index']);
    Route::get('purchases/{purchase}/edit', [PurchaseAPIController::class, 'edit']);
    Route::get('purchases/{purchase}', [PurchaseAPIController::class, 'show'])->name('purchases.show');
    Route::get(
        'purchase-pdf-download/{purchase}',
        [PurchaseAPIController::class, 'pdfDownload']
    )->name('purchase-pdf-download');
    Route::get('purchase-info/{purchase}', [PurchaseAPIController::class, 'purchaseInfo'])->name('purchase-info');

    Route::middleware('permission:manage_adjustments')->group(function () {
        Route::resource('adjustments', AdjustmentAPIController::class);
    });

    Route::prefix('inventory-counts')->group(function () {
        Route::middleware('permission:view_inventory_counts|perform_inventory_counts|approve_inventory_counts')->group(function () {
            Route::get('/', [InventoryCountAPIController::class, 'index']);
            Route::get('/{inventoryCount}', [InventoryCountAPIController::class, 'show']);
        });
        Route::middleware('permission:perform_inventory_counts')->group(function () {
            Route::post('/', [InventoryCountAPIController::class, 'store']);
            Route::patch('/{inventoryCount}/items/{item}', [InventoryCountAPIController::class, 'updateItem']);
            Route::post('/{inventoryCount}/submit', [InventoryCountAPIController::class, 'submit']);
        });
        Route::middleware('permission:perform_inventory_counts|approve_inventory_counts')->group(function () {
            Route::post('/{inventoryCount}/cancel', [InventoryCountAPIController::class, 'cancel']);
        });
        Route::middleware('permission:approve_inventory_counts')->group(function () {
            Route::post('/{inventoryCount}/approve', [InventoryCountAPIController::class, 'approve']);
        });
    });

    //purchase return routes
    Route::middleware('permission:manage_purchase_return')->group(function () {
        Route::resource('purchases-return', PurchaseReturnAPIController::class)->only(['store', 'update', 'destroy']);
        Route::get('purchases-return', [PurchaseReturnAPIController::class, 'index']);
        Route::get('purchases-return/{purchasesReturn}/edit', [PurchaseReturnAPIController::class, 'edit']);
        Route::get('purchases-return/{id}', [PurchaseReturnAPIController::class, 'show'])->name('purchases-return.show');
        Route::get(
            'purchase-return-info/{purchase_return}',
            [PurchaseReturnAPIController::class, 'purchaseReturnInfo']
        )->name('purchase-return-info');
        Route::get(
            'purchase-return-pdf-download/{purchase_return}',
            [PurchaseReturnAPIController::class, 'pdfDownload']
        )->name('purchase-return-pdf-download');
    });

    // warehouse report
    Route::get('warehouse-report', [WarehouseAPIController::class, 'warehouseReport'])->name('report-warehouse');
    Route::get(
        'sales-report-excel',
        [ReportAPIController::class, 'getWarehouseSaleReportExcel']
    )->name('report-getSaleReportExcel');
    Route::get(
        'sales-report-pdf',
        [SaleAPIController::class, 'salesReportPdf']
    );
    Route::get(
        'purchases-report-excel',
        [ReportAPIController::class, 'getWarehousePurchaseReportExcel']
    );
    Route::get(
        'sales-return-report-excel',
        [ReportAPIController::class, 'getWarehouseSaleReturnReportExcel']
    )->name('report-getSaleReturnReportExcel');
    Route::get(
        'purchases-return-report-excel',
        [
            ReportAPIController::class,
            'getWarehousePurchaseReturnReportExcel',
        ]
    )->name('report-getPurchaseReturnReportExcel');
    Route::get(
        'expense-report-excel',
        [ReportAPIController::class, 'getWarehouseExpenseReportExcel']
    )->name('report-getExpenseReportExcel');

    //sale report
    Route::get(
        'total-sale-report-excel',
        [ReportAPIController::class, 'getSalesReportExcel']
    )->name('report-getSalesReportExcel');

    // purchase report
    Route::get(
        'total-purchase-report-excel',
        [ReportAPIController::class, 'getPurchaseReportExcel']
    );
    // top-selling product report
    Route::get(
        'top-selling-product-report-excel',
        [ReportAPIController::class, 'getSellingProductReportExcel']
    );
    Route::get(
        'top-selling-product-report',
        [ReportAPIController::class, 'getSellingProductReport']
    );

    Route::get('supplier-report', [ReportAPIController::class, 'getSupplierReport']);

    Route::get('supplier-purchases-report/{supplier_id}', [ReportAPIController::class, 'getSupplierPurchasesReport']);
    Route::get(
        'supplier-purchases-return-report/{supplier_id}',
        [ReportAPIController::class, 'getSupplierPurchasesReturnReport']
    );
    Route::get('supplier-report-info/{supplier_id}', [ReportAPIController::class, 'getSupplierInfo']);

    // profit loss report
    Route::get('profit-loss-report', [ReportAPIController::class, 'getProfitLossReport']);

    // best customers report

    Route::get('best-customers-report', [ReportAPIController::class, 'getBestCustomersReport']);
    Route::get('best-customers-pdf-download', [CustomerAPIController::class, 'bestCustomersPdfDownload']);

    //customer all report
    Route::get('customer-report', [ReportAPIController::class, 'getCustomerReport']);
    Route::get('customer-payments-report/{customer}', [ReportAPIController::class, 'getCustomerPaymentsReport']);
    Route::get('customer-info/{customer}', [ReportAPIController::class, 'getCustomerInfo']);
    Route::get('customer-pdf-download/{customer}', [CustomerAPIController::class, 'pdfDownload']);
    Route::get('customer-sales-pdf-download/{customer}', [CustomerAPIController::class, 'customerSalesPdfDownload']);
    Route::get('customers/{customer}/sales-summary', [CustomerAPIController::class, 'salesSummary']);
    Route::get('customers/{customer}/credit-profile', [CustomerAPIController::class, 'creditProfile'])
        ->middleware('permission:manage_customers|manage_sale|manage_pos_screen|view_accounts_receivable|collect_accounts_receivable|manage_accounts_receivable');
    Route::get('customers/{customer}/sales-detail', [CustomerAPIController::class, 'salesDetail']);
    Route::get(
        'customer-quotations-pdf-download/{customer}',
        [CustomerAPIController::class, 'customerQuotationsPdfDownload']
    );
    Route::get(
        'customer-returns-pdf-download/{customer}',
        [CustomerAPIController::class, 'customerReturnsPdfDownload']
    );
    Route::get(
        'customer-payments-pdf-download/{customer}',
        [CustomerAPIController::class, 'customerPaymentsPdfDownload']
    );

    //Warehouse Products alert Quantity Report
    Route::get('product-stock-alerts/{warehouse_id?}', [ReportAPIController::class, 'stockAlerts'])
        ->middleware('permission:manage_reports');

    //stock report
    Route::get('stock-report', [ManageStockAPIController::class, 'stockReport'])
        ->middleware('permission:manage_reports')->name('report-stockReport');
    Route::get('stock-report-excel', [ReportAPIController::class, 'stockReportExcel'])
        ->middleware('permission:manage_reports')->name('report-stockReportExcel');
    Route::get(
        'get-sale-product-report',
        [SaleAPIController::class, 'getSaleProductReport']
    )->name('report-get-sale-product-report');
    Route::get(
        'get-purchase-product-report',
        [PurchaseAPIController::class, 'getPurchaseProductReport']
    )->name('report-get-purchase-product-report');
    Route::get(
        'get-sale-return-product-report',
        [SaleReturnAPIController::class, 'getSaleReturnProductReport']
    );
    Route::get('get-purchase-return-product-report', [
        PurchaseReturnAPIController::class,
        'getPurchaseReturnProductReport',
    ]);

    // Today sale overall report

    Route::get('today-sales-overall-report', [ReportAPIController::class, 'getTodaySalesOverallReport']);

    // stock report excel
    Route::get('get-product-sale-report-excel', [ReportAPIController::class, 'getProductSaleReportExport']);
    Route::get('get-product-purchase-report-excel', [ReportAPIController::class, 'getPurchaseProductReportExport']);
    Route::get(
        'get-product-sale-return-report-excel',
        [ReportAPIController::class, 'getSaleReturnProductReportExport']
    );
    Route::get(
        'get-product-purchase-return-report-excel',
        [ReportAPIController::class, 'getPurchaseReturnProductReportExport']
    );
    Route::get('get-product-count', [ReportAPIController::class, 'getProductQuantity'])
        ->middleware('permission:manage_reports');

    Route::get('config', [UserAPIController::class, 'config']);
    Route::get('my-organizations', [OrganizationAPIController::class, 'mine']);
    Route::get('current-organization', [OrganizationAPIController::class, 'current']);
    Route::get('my-stores', [StoreAPIController::class, 'misTiendas']);

    // POS Register routes
    Route::get('get-register-details', [POSRegisterAPIController::class, 'getRegisterDetails']);
    Route::post('register-entry', [POSRegisterAPIController::class, 'entry']);
    Route::get('available-cash-registers', [POSRegisterAPIController::class, 'availableCashRegisters']);
    Route::post('register-close', [POSRegisterAPIController::class, 'closeRegister']);
    Route::get('register-report/{session}/movements', [POSRegisterAPIController::class, 'registerReportMovements']);
    Route::get('register-report', [POSRegisterAPIController::class, 'registerReport']);
    Route::middleware('permission:manage_cash_control|view_own_cash_session|create_cash_income|create_cash_expense|withdraw_cash|view_cash_supervision|view_cash_closures|manage_cash_registers|reverse_cash_movement|transfer_cash|review_cash_closure')
        ->prefix('cash-control')->group(function () {
        Route::get('overview', [CashControlAPIController::class, 'overview']);
        Route::get('movements', [CashControlAPIController::class, 'movements'])
            ->middleware('permission:manage_cash_control|view_own_cash_session|create_cash_income|create_cash_expense|withdraw_cash|reverse_cash_movement|transfer_cash');
        Route::get('sessions/{session}/movements', [CashControlAPIController::class, 'supervisedMovements'])
            ->middleware('permission:manage_cash_control|view_cash_supervision');
        Route::post('movements', [CashControlAPIController::class, 'storeMovement']);
        Route::post('movements/{cashMovement}/reverse', [CashControlAPIController::class, 'reverse'])
            ->middleware('permission:reverse_cash_movement');
        Route::post('transfers', [CashControlAPIController::class, 'transfer'])
            ->middleware('permission:transfer_cash');
        Route::post('registers', [CashControlAPIController::class, 'storeRegister'])
            ->middleware('permission:manage_cash_control|manage_cash_registers');
        Route::patch('registers/{cashRegister}', [CashControlAPIController::class, 'updateRegister'])
            ->middleware('permission:manage_cash_control|manage_cash_registers');
        Route::get('sessions', [CashControlAPIController::class, 'sessions'])
            ->middleware('permission:manage_cash_control|view_cash_closures|review_cash_closure');
        Route::post('sessions/{session}/review', [CashControlAPIController::class, 'reviewClosure'])
            ->middleware('permission:review_cash_closure');
    });

    Route::middleware('permission:view_accounts_receivable|collect_accounts_receivable|manage_accounts_receivable')
        ->prefix('accounts-receivable')->group(function () {
        Route::get('/', [AccountsReceivableAPIController::class, 'index']);
        Route::get('summary', [AccountsReceivableAPIController::class, 'summary']);
        Route::get('customers', [AccountsReceivableAPIController::class, 'customers']);
        Route::get('customers/{customer}/statement', [AccountsReceivableAPIController::class, 'customerStatement']);
        Route::post('customers/{customer}/payments', [AccountsReceivableAPIController::class, 'collectCustomer'])
            ->middleware('permission:collect_accounts_receivable');
        Route::get('{sale}', [AccountsReceivableAPIController::class, 'show']);
        Route::post('{sale}/payments', [AccountsReceivableAPIController::class, 'collect'])
            ->middleware('permission:collect_accounts_receivable');
        Route::post('{sale}/activities', [AccountsReceivableAPIController::class, 'storeActivity'])
            ->middleware('permission:collect_accounts_receivable|manage_accounts_receivable');
        Route::patch('{sale}/terms', [AccountsReceivableAPIController::class, 'updateTerms'])
            ->middleware('permission:manage_accounts_receivable');
    });

    // Coupon Code Routes
    Route::resource('coupon-codes', CouponCodeAPIController::class)
        ->middleware('permission:manage_products');
});

Route::middleware([
    'auth:sanctum',
    'abilities:offline-sales:sync',
    'store.context',
    'subscription.active',
    'permission:manage_sale|manage_pos_screen',
    'throttle:30,1',
])->post('offline-sync/sales', [OfflineSaleSyncController::class, 'store']);

Route::middleware([
    'auth:sanctum',
    'abilities:offline-sales:sync',
    'store.context',
    'subscription.active',
    'permission:manage_sale|manage_pos_screen',
    'throttle:120,1',
])->get('offline-sync/sales/{clientUuid}/status', [OfflineSaleSyncController::class, 'status']);

Route::middleware([
    'auth:sanctum',
    'abilities:offline-sales:sync',
    'store.context',
    'subscription.active',
    'permission:manage_sale|manage_pos_screen',
    'throttle:60,1',
])->post('offline-sync/sales/diagnose', [OfflineSaleSyncController::class, 'diagnose']);

Route::middleware([
    'auth:sanctum',
    'abilities:offline-customers:sync',
    'store.context',
    'subscription.active',
    'permission:manage_customers|manage_pos_screen',
    'throttle:30,1',
])->post('offline-sync/customers', [OfflineCustomerSyncController::class, 'store']);

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login');
Route::post('onboarding/register', [SaaSOnboardingController::class, 'store'])
    ->middleware('throttle:3,1')
    ->name('saas.onboarding.register');

Route::post(
    '/forgot-password',
    [AuthController::class, 'sendPasswordResetLinkEmail']
)->middleware('throttle:5,1')->name('password.email');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:5,1')->name('password.reset');

// SIN auth:sanctum a propósito -- se usa en la pantalla de Login antes
// de tener sesión (ver Login.js) -- pero el sidebar YA autenticado
// también la llama (fetchFrontSetting() se dispara en decenas de
// pantallas). store.context (ResolveActiveStore) no exige un usuario
// -- si no lo hay, no hace nada (ver su propio guard `if (!$user)`) --
// así que agregarlo acá sin auth:sanctum resuelve la tienda activa
// SOLO cuando sí hay sesión, sin romper la pantalla de Login. Sin esto,
// currentStoreId() era SIEMPRE null en esta ruta, incluso ya logueado
// con una tienda elegida -- getLogoUrl() (y cualquier otro dato
// store-scoped que pase por acá) caía siempre al fallback de sistema,
// nunca al de la tienda activa real.
Route::get('front-setting', [SettingAPIController::class, 'getFrontSettingsValue'])
    ->middleware('store.context')
    ->name('front-settings');

Route::post('validate-auth-token', [AuthController::class, 'isValidToken']);

require __DIR__ . '/m1.php';
