import { Permissions } from "./constants";
import { lazyWithRetry } from "./shared/lazyWithRetry";

import Dashboard from "./components/dashboard/Dashboard";

// Maestros pequeños que casi siempre se navegan seguidos desde el mismo
// menú (Catálogo) -- agrupados en un solo chunk "catalog-masters".
const Brands = lazyWithRetry(() => import(/* webpackChunkName: "catalog-masters" */ "./components/brands/Brands"), "Brands");
const Currencies = lazyWithRetry(() => import(/* webpackChunkName: "catalog-masters" */ "./components/currency/Currencies"), "Currencies");
const ProductCategory = lazyWithRetry(() => import(/* webpackChunkName: "catalog-masters" */ "./components/productCategory/ProductCategory"), "ProductCategory");
const Units = lazyWithRetry(() => import(/* webpackChunkName: "catalog-masters" */ "./components/units/Units"), "Units");
const BaseUnits = lazyWithRetry(() => import(/* webpackChunkName: "catalog-masters" */ "./components/base-unit/BaseUnits"), "BaseUnits");
const Variation = lazyWithRetry(() => import(/* webpackChunkName: "catalog-masters" */ "./components/variation/Variation"), "Variation");

// Sucursales/almacenes.
const Warehouses = lazyWithRetry(() => import(/* webpackChunkName: "warehouse" */ "./components/warehouse/Warehouses"), "Warehouses");
const CreateWarehouse = lazyWithRetry(() => import(/* webpackChunkName: "warehouse" */ "./components/warehouse/CreateWarehouse"), "CreateWarehouse");
const EditWarehouse = lazyWithRetry(() => import(/* webpackChunkName: "warehouse" */ "./components/warehouse/EditWarehouse"), "EditWarehouse");
const WarehouseDetail = lazyWithRetry(() => import(/* webpackChunkName: "warehouse" */ "./components/warehouse/WarehouseDetail"), "WarehouseDetail");

// Proveedores.
const Suppliers = lazyWithRetry(() => import(/* webpackChunkName: "supplier" */ "./components/supplier/Suppliers"), "Suppliers");
const CreateSupplier = lazyWithRetry(() => import(/* webpackChunkName: "supplier" */ "./components/supplier/CreateSupplier"), "CreateSupplier");
const EditSupplier = lazyWithRetry(() => import(/* webpackChunkName: "supplier" */ "./components/supplier/EditSupplier"), "EditSupplier");

// Clientes.
const Customers = lazyWithRetry(() => import(/* webpackChunkName: "customer" */ "./components/customer/Customers"), "Customers");
const CreateCustomer = lazyWithRetry(() => import(/* webpackChunkName: "customer" */ "./components/customer/CreateCustomer"), "CreateCustomer");
const EditCustomer = lazyWithRetry(() => import(/* webpackChunkName: "customer" */ "./components/customer/EditCustomer"), "EditCustomer");

// Usuarios y perfil.
const User = lazyWithRetry(() => import(/* webpackChunkName: "user" */ "./components/users/User"), "User");
const CreateUser = lazyWithRetry(() => import(/* webpackChunkName: "user" */ "./components/users/CreateUser"), "CreateUser");
const EditUser = lazyWithRetry(() => import(/* webpackChunkName: "user" */ "./components/users/EditUser"), "EditUser");
const UserDetail = lazyWithRetry(() => import(/* webpackChunkName: "user" */ "./components/users/UserDetail"), "UserDetail");
const UpdateProfile = lazyWithRetry(() => import(/* webpackChunkName: "user" */ "./components/user-profile/UpdateProfile"), "UpdateProfile");

// Suscripción SaaS: chunk propio, solo lo baja quien administra la cuenta.
const SubscriptionManager = lazyWithRetry(() => import(/* webpackChunkName: "subscription" */ "./components/subscription/SubscriptionManager"), "SubscriptionManager");

// Productos.
const Product = lazyWithRetry(() => import(/* webpackChunkName: "product" */ "./components/product/Product"), "Product");
const CreateProduct = lazyWithRetry(() => import(/* webpackChunkName: "product" */ "./components/product/CreateProduct"), "CreateProduct");
const EditProduct = lazyWithRetry(() => import(/* webpackChunkName: "product" */ "./components/product/EditProduct"), "EditProduct");
const ProductDetail = lazyWithRetry(() => import(/* webpackChunkName: "product" */ "./components/product/ProductDetail"), "ProductDetail");
const ProductKits = lazyWithRetry(() => import(/* webpackChunkName: "product" */ "./components/productKits/ProductKits"), "ProductKits");

// Configuración.
const Settings = lazyWithRetry(() => import(/* webpackChunkName: "settings" */ "./components/settings/Settings"), "Settings");
const Prefixes = lazyWithRetry(() => import(/* webpackChunkName: "settings" */ "./components/settings/Prefixes"), "Prefixes");
const MailSettings = lazyWithRetry(() => import(/* webpackChunkName: "settings" */ "./components/settings/MailSettings"), "MailSettings");

// Gastos.
const ExpenseCategory = lazyWithRetry(() => import(/* webpackChunkName: "expense" */ "./components/expense-category/ExpenseCategory"), "ExpenseCategory");
const Expenses = lazyWithRetry(() => import(/* webpackChunkName: "expense" */ "./components/expense/Expenses"), "Expenses");
const CreateExpense = lazyWithRetry(() => import(/* webpackChunkName: "expense" */ "./components/expense/CreateExpense"), "CreateExpense");
const EditExpense = lazyWithRetry(() => import(/* webpackChunkName: "expense" */ "./components/expense/EditExpense"), "EditExpense");

// Compras.
const Purchases = lazyWithRetry(() => import(/* webpackChunkName: "purchase" */ "./components/purchase/Purchases"), "Purchases");
const CreatePurchase = lazyWithRetry(() => import(/* webpackChunkName: "purchase" */ "./components/purchase/CreatePurchase"), "CreatePurchase");
const EditPurchase = lazyWithRetry(() => import(/* webpackChunkName: "purchase" */ "./components/purchase/EditPurchase"), "EditPurchase");
const PurchaseDetails = lazyWithRetry(() => import(/* webpackChunkName: "purchase" */ "./components/purchase/PurchaseDetails"), "PurchaseDetails");

// Pantalla del POS y su impresión: chunk propio -- es la pantalla más
// pesada y de uso más frecuente, no conviene mezclarla con nada del admin.
const PosMainPage = lazyWithRetry(() => import(/* webpackChunkName: "pos-screen" */ "./frontend/components/PosMainPage"), "PosMainPage");
const PrintData = lazyWithRetry(() => import(/* webpackChunkName: "pos-print" */ "./frontend/components/printModal/PrintData"), "PrintData");

// Ventas.
const Sales = lazyWithRetry(() => import(/* webpackChunkName: "sale" */ "./components/sales/Sales"), "Sales");
const CreateSale = lazyWithRetry(() => import(/* webpackChunkName: "sale" */ "./components/sales/CreateSale"), "CreateSale");
const EditSale = lazyWithRetry(() => import(/* webpackChunkName: "sale" */ "./components/sales/EditSale"), "EditSale");
const SaleDetails = lazyWithRetry(() => import(/* webpackChunkName: "sale" */ "./components/sales/SaleDetails"), "SaleDetails");

// Devoluciones de venta.
const SaleReturn = lazyWithRetry(() => import(/* webpackChunkName: "sale-return" */ "./components/saleReturn/SaleReturn"), "SaleReturn");
const CreateSaleReturn = lazyWithRetry(() => import(/* webpackChunkName: "sale-return" */ "./components/saleReturn/CreateSaleReturn"), "CreateSaleReturn");
const EditSaleReturn = lazyWithRetry(() => import(/* webpackChunkName: "sale-return" */ "./components/saleReturn/EditSaleReturn"), "EditSaleReturn");
const SaleReturnDetails = lazyWithRetry(() => import(/* webpackChunkName: "sale-return" */ "./components/saleReturn/SaleReturnDetails"), "SaleReturnDetails");
const EditSaleReturnFromSale = lazyWithRetry(() => import(/* webpackChunkName: "sale-return" */ "./components/saleReturn/EditSaleReturnFromSale"), "EditSaleReturnFromSale");
const CreditNotes = lazyWithRetry(() => import(/* webpackChunkName: "credit-note" */ "./components/creditNote/CreditNotes"), "CreditNotes");
const CreateCreditNote = lazyWithRetry(() => import(/* webpackChunkName: "credit-note" */ "./components/creditNote/CreateCreditNote"), "CreateCreditNote");
const CreditNoteDetails = lazyWithRetry(() => import(/* webpackChunkName: "credit-note" */ "./components/creditNote/CreditNoteDetails"), "CreditNoteDetails");

// Devoluciones de compra.
const PurchaseReturn = lazyWithRetry(() => import(/* webpackChunkName: "purchase-return" */ "./components/purchaseReturn/PurchaseReturn"), "PurchaseReturn");
const CreatePurchaseReturn = lazyWithRetry(() => import(/* webpackChunkName: "purchase-return" */ "./components/purchaseReturn/CreatePurchaseReturn"), "CreatePurchaseReturn");
const EditPurchaseReturn = lazyWithRetry(() => import(/* webpackChunkName: "purchase-return" */ "./components/purchaseReturn/EditPurchaseReturn"), "EditPurchaseReturn");
const PurchaseReturnDetails = lazyWithRetry(() => import(/* webpackChunkName: "purchase-return" */ "./components/purchaseReturn/PurchaseReturnDetails"), "PurchaseReturnDetails");

// Cotizaciones.
const Quotations = lazyWithRetry(() => import(/* webpackChunkName: "quotation" */ "./components/quotations/Quotations"), "Quotations");
const CreateQuotation = lazyWithRetry(() => import(/* webpackChunkName: "quotation" */ "./components/quotations/CreateQuotation"), "CreateQuotation");
const EditQuotation = lazyWithRetry(() => import(/* webpackChunkName: "quotation" */ "./components/quotations/EditQuotation"), "EditQuotation");
const CreateQuotationSale = lazyWithRetry(() => import(/* webpackChunkName: "quotation" */ "./components/quotations/CreateQuotationSale"), "CreateQuotationSale");
const QuotationDetails = lazyWithRetry(() => import(/* webpackChunkName: "quotation" */ "./components/quotations/QuotationDetails"), "QuotationDetails");

// Reportes: son 14 pantallas chicas de solo-lectura que casi siempre se
// visitan varias seguidas desde el mismo menú -- un solo chunk grande
// para todo el módulo de reportes.
const WarehouseReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/warehouseReport/WarehouseReport"), "WarehouseReport");
const SaleReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/saleReport/SaleReport"), "SaleReport");
const StockReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/stockReport/StockReport"), "StockReport");
const StockDetails = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/stockReport/StockDetails"), "StockDetails");
const TopSellingProductsReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/topSellingReport/TopSellingProductsReport"), "TopSellingProductsReport");
const PurchaseReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/purchaseReport/PurchaseReport"), "PurchaseReport");
const ProductQuantityReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/productQuantityReport/ProductQuantityReport"), "ProductQuantityReport");
const SuppliersReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/supplier-report/SuppliersReport"), "SuppliersReport");
const SupplierReportDetails = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/supplier-report/SupplierReportDetails"), "SupplierReportDetails");
const BestCustomerReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/best-customerReport/BestCustomerReport"), "BestCustomerReport");
const ProfitLossReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/ProfitLossReport/ProfitLossReport"), "ProfitLossReport");
const CustomerReportDetails = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/customer-report/CustomerReportDetails"), "CustomerReportDetails");
const CustomersReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/customer-report/CustomersReport"), "CustomersReport");
const RegisterReport = lazyWithRetry(() => import(/* webpackChunkName: "reports" */ "./components/report/registerReport/RegisterReport"), "RegisterReport");
const CashControl = lazyWithRetry(() => import(/* webpackChunkName: "cash-control" */ "./components/cashControl/CashControl"), "CashControl");
const AccountsReceivable = lazyWithRetry(() => import(/* webpackChunkName: "accounts-receivable" */ "./components/accountsReceivable/AccountsReceivable"), "AccountsReceivable");

// Impresión de códigos de barra: chunk propio, poco uso.
const PrintBarcode = lazyWithRetry(() => import(/* webpackChunkName: "print-barcode" */ "./components/printBarcode/PrintBarcode"), "PrintBarcode");

// Roles y permisos.
const Role = lazyWithRetry(() => import(/* webpackChunkName: "roles" */ "./components/roles/Role"), "Role");
const CreateRole = lazyWithRetry(() => import(/* webpackChunkName: "roles" */ "./components/roles/CreateRole"), "CreateRole");
const EditRole = lazyWithRetry(() => import(/* webpackChunkName: "roles" */ "./components/roles/EditRole"), "EditRole");

// Administración de tiendas (multitienda).
const Stores = lazyWithRetry(() => import(/* webpackChunkName: "stores" */ "./components/stores/Stores"), "Stores");
const CatalogSettings = lazyWithRetry(() => import(/* webpackChunkName: "catalog-settings" */ "./components/catalog/CatalogSettings"), "CatalogSettings");
const CatalogOrders = lazyWithRetry(() => import(/* webpackChunkName: "catalog-orders" */ "./components/catalogOrders/CatalogOrders"), "CatalogOrders");

// Ajustes de inventario.
const Adjustments = lazyWithRetry(() => import(/* webpackChunkName: "adjustments" */ "./components/adjustments/Adjustments"), "Adjustments");
const CreateAdjustment = lazyWithRetry(() => import(/* webpackChunkName: "adjustments" */ "./components/adjustments/CreateAdjustment"), "CreateAdjustment");
const EditAdjustMent = lazyWithRetry(() => import(/* webpackChunkName: "adjustments" */ "./components/adjustments/EditAdjustMent"), "EditAdjustMent");

// Transferencias entre sucursales.
import Transfers from "./components/transfers/Transfers";
import EditTransfer from "./components/transfers/EditTransfer";
import CreateTransfer from "./components/transfers/CreateTransfer";

// Plantillas de correo/SMS.
const EmailTemplates = lazyWithRetry(() => import(/* webpackChunkName: "templates" */ "./components/Email-templates/EmailTemplates"), "EmailTemplates");
const EditEmailTemplate = lazyWithRetry(() => import(/* webpackChunkName: "templates" */ "./components/Email-templates/EditEmailTemplate"), "EditEmailTemplate");
const SmsTemplates = lazyWithRetry(() => import(/* webpackChunkName: "templates" */ "./components/sms-templates/SmsTemplates"), "SmsTemplates");
const EditSmsTemplate = lazyWithRetry(() => import(/* webpackChunkName: "templates" */ "./components/sms-templates/EditSmsTemplate"), "EditSmsTemplate");

// SMS API: chunk propio, poco uso.
const SmsApi = lazyWithRetry(() => import(/* webpackChunkName: "sms-api" */ "./components/sms-api/SmsApi"), "SmsApi");

// Idiomas.
const Language = lazyWithRetry(() => import(/* webpackChunkName: "languages" */ "./components/languages/Language"), "Language");
const EditLanguageData = lazyWithRetry(() => import(/* webpackChunkName: "languages" */ "./components/languages/EditLanguageData"), "EditLanguageData");

// Logs de acceso: chunk propio, poco uso.
const LoginLogs = lazyWithRetry(() => import(/* webpackChunkName: "login-logs" */ "./components/loginLogs/LoginLogs"), "LoginLogs");

// Dashboard del vendedor: chunk propio, solo lo cargan los usuarios con
// permiso de vendedor.
const SellerDashboard = lazyWithRetry(() => import(/* webpackChunkName: "seller-dashboard" */ "./components/sellerDashboard/SellerDashboard"), "SellerDashboard");

const Kardex = lazyWithRetry(() => import(/* webpackChunkName: "kardex" */ "./components/kardex/Kardex"), "Kardex");
const InventoryCounts = lazyWithRetry(() => import(/* webpackChunkName: "inventory-counts" */ "./components/inventoryCounts/InventoryCounts"), "InventoryCounts");

// Configuración de facturación electrónica SRI.
const SriConfigPage = lazyWithRetry(() => import(/* webpackChunkName: "sri-config" */ "./components/sri/SriConfigPage"), "SriConfigPage");
const ElectronicInvoices = lazyWithRetry(() => import(/* webpackChunkName: "electronic-invoices" */ "./components/electronicInvoices/ElectronicInvoices"), "ElectronicInvoices");

export const route = [
    {
        path: "dashboard",
        ele: <Dashboard />,
        permission: Permissions.MANAGE_DASHBOARD,
    },
    {
        path: "my-sales",
        ele: <SellerDashboard />,
        permission: Permissions.MANAGE_SELLER_DASHBOARD,
    },
    {
        path: "kardex",
        ele: <Kardex />,
        permission: Permissions.MANAGE_KARDEX,
    },
    {
        path: "inventory-counts",
        ele: <InventoryCounts />,
        permission: Permissions.INVENTORY_COUNTS_ACCESS,
    },
    {
        path: "inventory-counts/:id",
        ele: <InventoryCounts />,
        permission: Permissions.INVENTORY_COUNTS_ACCESS,
    },
    {
        path: "sri-config",
        ele: <SriConfigPage />,
        permission: Permissions.MANAGE_SRI_CONFIG,
    },
    {
        path: "electronic-invoices",
        ele: <ElectronicInvoices />,
        permission: Permissions.MANAGE_ELECTRONIC_INVOICES,
    },
    {
        path: "brands",
        ele: <Brands />,
        permission: Permissions.MANAGE_BRANDS,
    },
    {
        path: "currencies",
        ele: <Currencies />,
        permission: Permissions.MANAGE_CURRENCY,
    },
    {
        path: "warehouse",
        ele: <Warehouses />,
        permission: Permissions.MANAGE_WAREHOUSES,
    },
    {
        path: "warehouse/create",
        ele: <CreateWarehouse />,
        permission: Permissions.MANAGE_WAREHOUSES,
    },
    {
        path: "warehouse/edit/:id",
        ele: <EditWarehouse />,
        permission: Permissions.MANAGE_WAREHOUSES,
    },
    {
        path: "warehouse/detail/:id",
        ele: <WarehouseDetail />,
        permission: Permissions.MANAGE_WAREHOUSES,
    },
    {
        path: "product-categories",
        ele: <ProductCategory />,
        permission: Permissions.MANAGE_PRODUCT_CATEGORIES,
    },
    {
        path: "variations",
        ele: <Variation />,
        permission: Permissions.MANAGE_VARIATIONS,
    },

    {
        path: "units",
        ele: <Units />,
        permission: Permissions.MANAGE_UNITS,
    },
    {
        path: "base-units",
        ele: <BaseUnits />,
        permission: Permissions.MANAGE_UNITS,
    },
    {
        path: "suppliers",
        ele: <Suppliers />,
        permission: Permissions.MANAGE_SUPPLIERS,
    },
    {
        path: "suppliers/create",
        ele: <CreateSupplier />,
        permission: Permissions.MANAGE_SUPPLIERS,
    },
    {
        path: "suppliers/edit/:id",
        ele: <EditSupplier />,
        permission: Permissions.MANAGE_SUPPLIERS,
    },
    {
        path: "customers",
        ele: <Customers />,
        permission: Permissions.MANAGE_CUSTOMERS,
    },
    {
        path: "customers/create",
        ele: <CreateCustomer />,
        permission: Permissions.MANAGE_CUSTOMERS,
    },
    {
        path: "customers/edit/:id",
        ele: <EditCustomer />,
        permission: Permissions.MANAGE_CUSTOMERS,
    },
    {
        path: "users",
        ele: <User />,
        permission: Permissions.MANAGE_USER,
    },
    {
        path: "users/create",
        ele: <CreateUser />,
        permission: Permissions.MANAGE_USER,
    },
    {
        path: "users/edit/:id",
        ele: <EditUser />,
        permission: Permissions.MANAGE_USER,
    },
    {
        path: "users/detail/:id",
        ele: <UserDetail />,
        permission: Permissions.MANAGE_USER,
    },
    {
        path: "profile/edit",
        ele: <UpdateProfile />,
        permission: "",
    },
    {
        // Sin permiso de menú: no existe un permiso "suscripción" en los
        // roles de tienda. Quién puede entrar lo decide el backend
        // (config.can_manage_subscription) y lo revalida el propio
        // componente antes de renderizar.
        path: "subscription",
        ele: <SubscriptionManager />,
        permission: "",
    },
    {
        path: "products",
        ele: <Product />,
        permission: Permissions.MANAGE_PRODUCTS,
    },
    {
        path: "products/create",
        ele: <CreateProduct />,
        permission: Permissions.MANAGE_PRODUCTS,
    },
    {
        path: "products/edit/:id",
        ele: <EditProduct />,
        permission: Permissions.MANAGE_PRODUCTS,
    },
    {
        path: "products/detail/:id",
        ele: <ProductDetail />,
        permission: Permissions.MANAGE_PRODUCTS,
    },
    {
        path: "product-kits",
        ele: <ProductKits />,
        permission: Permissions.MANAGE_PRODUCTS,
    },
    {
        path: "adjustments",
        ele: <Adjustments />,
        permission: Permissions.MANAGE_ADJUSTMENTS,
    },
    {
        path: "adjustments/create",
        ele: <CreateAdjustment />,
        permission: Permissions.MANAGE_ADJUSTMENTS,
    },
    {
        path: "adjustments/:id",
        ele: <EditAdjustMent />,
        permission: Permissions.MANAGE_ADJUSTMENTS,
    },
    {
        path: "settings",
        ele: <Settings />,
        permission: Permissions.MANAGE_SETTING,
    },
    {
        path: "prefixes",
        ele: <Prefixes />,
        permission: Permissions.MANAGE_SETTING,
    },
    {
        path: "mail-settings",
        ele: <MailSettings />,
        permission: Permissions.MANAGE_SETTING,
    },
    {
        path: "expense-categories",
        ele: <ExpenseCategory />,
        permission: Permissions.MANAGE_EXPENSES_CATEGORIES,
    },
    {
        path: "expenses",
        ele: <Expenses />,
        permission: Permissions.MANAGE_EXPENSES,
    },
    {
        path: "expenses/create",
        ele: <CreateExpense />,
        permission: Permissions.MANAGE_EXPENSES,
    },
    {
        path: "expenses/edit/:id",
        ele: <EditExpense />,
        permission: Permissions.MANAGE_EXPENSES,
    },
    {
        path: "purchases",
        ele: <Purchases />,
        permission: Permissions.MANAGE_PURCHASE,
    },
    {
        path: "purchases/create",
        ele: <CreatePurchase />,
        permission: Permissions.MANAGE_PURCHASE,
    },
    {
        path: "purchases/edit/:id",
        ele: <EditPurchase />,
        permission: Permissions.MANAGE_PURCHASE,
    },
    {
        path: "purchases/detail/:id",
        ele: <PurchaseDetails />,
        permission: Permissions.MANAGE_PURCHASE,
    },
    {
        path: "pos",
        ele: <PosMainPage />,
        permission: Permissions.MANAGE_POS_SCREEN,
    },
    {
        path: "/payment",
        ele: <PrintData />,
        permission: "",
    },
    {
        path: "user-detail",
        ele: <UserDetail />,
        permission: Permissions.MANAGE_USER,
    },
    {
        path: "sales",
        ele: <Sales />,
        permission: Permissions.MANAGE_SALE,
    },
    {
        path: "sales/create",
        ele: <CreateSale />,
        permission: Permissions.MANAGE_SALE,
    },
    {
        path: "sales/edit/:id",
        ele: <EditSale />,
        permission: Permissions.MANAGE_SALE,
    },
    {
        path: "sales/return/:id",
        ele: <CreateSaleReturn />,
        permission: Permissions.MANAGE_SALE_RETURN,
    },
    {
        path: "sales/return/edit/:id",
        ele: <EditSaleReturnFromSale />,
        permission: Permissions.MANAGE_SALE_RETURN,
    },
    {
        path: "credit-notes",
        ele: <CreditNotes />,
        permission: Permissions.MANAGE_SALE,
    },
    {
        path: "credit-notes/create",
        ele: <CreateCreditNote />,
        permission: Permissions.MANAGE_SALE,
    },
    {
        path: "credit-notes/:id",
        ele: <CreditNoteDetails />,
        permission: Permissions.MANAGE_SALE,
    },
    {
        path: "quotations",
        ele: <Quotations />,
        permission: Permissions.MANAGE_QUOTATION,
    },
    {
        path: "quotations/create",
        ele: <CreateQuotation />,
        permission: Permissions.MANAGE_QUOTATION,
    },
    {
        path: "quotations/edit/:id",
        ele: <EditQuotation />,
        permission: Permissions.MANAGE_QUOTATION,
    },
    {
        path: "quotations/Create_sale/:id",
        ele: <CreateQuotationSale />,
        permission: Permissions.MANAGE_QUOTATION,
    },
    {
        path: "quotations/detail/:id",
        ele: <QuotationDetails />,
        permission: Permissions.MANAGE_QUOTATION,
    },
    {
        path: "sale-return",
        ele: <SaleReturn />,
        permission: Permissions.MANAGE_SALE_RETURN,
    },
    {
        path: "sale-return/edit/:id",
        ele: <EditSaleReturn />,
        permission: Permissions.MANAGE_SALE_RETURN,
    },
    {
        path: "sale-return/detail/:id",
        ele: <SaleReturnDetails />,
        permission: Permissions.MANAGE_SALE_RETURN,
    },
    {
        path: "sales/detail/:id",
        ele: <SaleDetails />,
        permission: Permissions.MANAGE_SALE,
    },
    {
        path: "purchase-return",
        ele: <PurchaseReturn />,
        permission: Permissions.MANAGE_PURCHASE_RETURN,
    },
    {
        path: "purchase-return/create",
        ele: <CreatePurchaseReturn />,
        permission: Permissions.MANAGE_PURCHASE_RETURN,
    },
    {
        path: "purchase-return/edit/:id",
        ele: <EditPurchaseReturn />,
        permission: Permissions.MANAGE_PURCHASE_RETURN,
    },
    {
        path: "purchase-return/detail/:id",
        ele: <PurchaseReturnDetails />,
        permission: Permissions.MANAGE_PURCHASE_RETURN,
    },
    {
        path: "report/report-warehouse",
        ele: <WarehouseReport />,
        permission: Permissions.MANAGE_REPORTS,
    },
    {
        path: "report/report-sale",
        ele: <SaleReport />,
        permission: Permissions.MANAGE_REPORTS,
    },
    {
        path: "report/report-stock",
        ele: <StockReport />,
        permission: Permissions.MANAGE_REPORTS,
    },
    {
        path: "report/report-detail-stock/:id",
        ele: <StockDetails />,
        permission: Permissions.MANAGE_REPORTS,
    },
    {
        path: "report/report-top-selling-products",
        ele: <TopSellingProductsReport />,
        permission: Permissions.MANAGE_REPORTS,
    },
    {
        path: "report/report-product-quantity",
        ele: <ProductQuantityReport />,
        permission: Permissions.MANAGE_REPORTS,
    },
    {
        path: "report/report-purchase",
        ele: <PurchaseReport />,
        permission: Permissions.MANAGE_REPORTS,
    },
    {
        path: "report/suppliers",
        ele: <SuppliersReport />,
        permission: Permissions.MANAGE_REPORTS,
    },
    {
        path: "report/profit-loss",
        ele: <ProfitLossReport />,
        permission: Permissions.MANAGE_REPORTS,
    },
    {
        path: "report/suppliers/details/:id",
        ele: <SupplierReportDetails />,
        permission: Permissions.MANAGE_REPORTS,
    },
    {
        path: "print/barcode",
        ele: <PrintBarcode />,
        permission: Permissions.MANAGE_PRODUCTS,
    },
    {
        path: "roles",
        ele: <Role />,
        permission: Permissions.MANAGE_ROLES,
    },
    {
        path: "roles/create",
        ele: <CreateRole />,
        permission: Permissions.MANAGE_ROLES,
    },
    {
        path: "roles/edit/:id",
        ele: <EditRole />,
        permission: Permissions.MANAGE_ROLES,
    },
    {
        path: "stores",
        ele: <Stores />,
        permission: Permissions.MANAGE_STORES,
    },
    {
        path: "catalog-orders",
        ele: <CatalogOrders />,
        permission: Permissions.MANAGE_CATALOG_ORDERS,
    },
    {
        path: "catalog-settings",
        ele: <CatalogSettings />,
        permission: Permissions.MANAGE_STORES,
    },
    {
        path: "transfers",
        ele: <Transfers />,
        permission: Permissions.MANAGE_TRANSFERS,
    },
    {
        path: "transfers/create",
        ele: <CreateTransfer />,
        permission: Permissions.MANAGE_TRANSFERS,
    },
    {
        path: "transfers/:id",
        ele: <EditTransfer />,
        permission: Permissions.MANAGE_TRANSFERS,
    },
    {
        path: "email-templates",
        ele: <EmailTemplates />,
        permission: Permissions.MANAGE_EMAIL_TEMPLATES,
    },
    {
        path: "email-templates/:id",
        ele: <EditEmailTemplate />,
        permission: Permissions.MANAGE_EMAIL_TEMPLATES,
    },
    {
        path: "sms-templates",
        ele: <SmsTemplates />,
        permission: Permissions.MANAGE_SMS_TEMPLATES,
    },
    {
        path: "sms-templates/:id",
        ele: <EditSmsTemplate />,
        permission: Permissions.MANAGE_SMS_TEMPLATES,
    },
    {
        path: "report/best-customers",
        ele: <BestCustomerReport />,
        permission: "",
    },
    {
        path: "report/customers",
        ele: <CustomersReport />,
        permission: "",
    },
    {
        path: "report/customers/details/:id",
        ele: <CustomerReportDetails />,
        permission: "",
    },
    {
        path: "report/register",
        ele: <RegisterReport />,
        permission: "",
    },
    {
        path: "cash-control",
        ele: <CashControl />,
        permission: Permissions.CASH_CONTROL_ACCESS,
    },
    {
        path: "accounts-receivable",
        ele: <AccountsReceivable />,
        permission: Permissions.ACCOUNTS_RECEIVABLE_ACCESS,
    },
    {
        path: "sms-api",
        ele: <SmsApi />,
        permission: Permissions.MANAGE_SMS_API,
    },
    {
        path: "languages",
        ele: <Language />,
        permission: Permissions.MANAGE_LANGUAGES,
    },
    {
        path: "languages/:id",
        ele: <EditLanguageData />,
        permission: Permissions.MANAGE_LANGUAGES,
    },
    {
        path: "login-logs",
        ele: <LoginLogs />,
        permission: "",
    },
];
