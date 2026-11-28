import _, { useState, useEffect, useRef, useCallback } from "react";
import { Button, Col, Container, Modal, Row, Table } from "react-bootstrap-v5";
import { connect, useDispatch, useSelector } from "react-redux";
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc';
import localizedFormat from 'dayjs/plugin/localizedFormat';
import isoWeek from 'dayjs/plugin/isoWeek';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(utc);
dayjs.extend(localizedFormat);
dayjs.extend(isoWeek);
dayjs.extend(relativeTime);
import { useReactToPrint } from "react-to-print";
import Category from "./Category";
import Product from "./product/Product";
import ProductCartList from "./cart-product/ProductCartList";
import {
    posSearchNameProduct,
    posSearchCodeProduct,
} from "../../store/action/pos/posfetchProductAction";
import ProductSearchbar from "./product/ProductSearchbar";
import { prepareCartArray } from "../shared/PrepareCartArray";
import { createCartSelector } from "../shared/catalogSelectors";
import ProductDetailsModel from "../shared/ProductDetailsModel";
import CartItemMainCalculation from "./cart-product/CartItemMainCalculation";
import PosHeader from "./header/PosHeader";
import PaymentButton from "./cart-product/PaymentButton";
import CashPaymentModel from "./cart-product/paymentModel/CashPaymentModel";
import PaymentSlipModal from "./paymentSlipModal/PaymentSlipModal";
import { fetchFrontSetting } from "../../store/action/frontSettingAction";
import { fetchSetting } from "../../store/action/settingAction";
import { calculateProductCost } from "../shared/SharedMethod";
import {
    fetchBrandClickable,
    posAllProduct,
} from "../../store/action/pos/posAllProductAction";
import TabTitle from "../../shared/tab-title/TabTitle";
import HeaderAllButton from "./header/HeaderAllButton";
import RegisterDetailsModel from "./register-detailsModal/RegisterDetailsModel";
import PrintRegisterDetailsData from "./printModal/PrintRegisterDetailsData";
import {
    closeRegisterAction,
    fetchTodaySaleOverAllReport,
    getAllRegisterDetailsAction,
} from "../../store/action/pos/posRegisterDetailsAction";
import {
    getFormattedMessage,
    getFormattedOptions,
} from "../../shared/sharedMethod";
import { paymentMethodOptions, posCashPaymentActionType, productActionType, toastType } from "../../constants";
import TopProgressBar from "../../shared/components/loaders/TopProgressBar";
import CustomerForm from "./customerModel/CustomerForm";
import HoldListModal from "./holdListModal/HoldListModal";
import { fetchHoldLists } from "../../store/action/pos/HoldListAction";
import { useNavigate } from "react-router";
import apiConfig from "../../config/apiConfig";
import PosCloseRegisterDetailsModel from "../../components/posRegister/PosCloseRegisterDetailsModel.js";
import { addToast } from "../../store/action/toastAction";
import OfflineCatalogStatus from "./offline/OfflineCatalogStatus";
import OfflineSalesModal from "./offline/OfflineSalesModal";
import {
    enqueueOfflineSale,
    createClientUuid,
    filterCatalogProducts,
    getOfflineCustomers,
    getOfflineSale,
    getOfflineSales,
    getOfflineSnapshot,
    getOfflineSyncCredential,
    OFFLINE_CATALOG_EVENT,
    OFFLINE_CUSTOMERS_EVENT,
    OFFLINE_SALES_EVENT,
    reserveOfflineCatalogStock,
    resetOfflineAuthFailures,
    resetOfflineCsrfFailures,
} from "../../offline/catalogStorage";
import { diagnoseOfflineSale, syncOfflineSales } from "../../offline/offlineSalesSync";
import { syncOfflineCustomers } from "../../offline/offlineCustomersSync";
import {
    ensureOfflineSyncCredential,
    requestOfflineSaleBackgroundSync,
    supportsOfflineBackgroundSync,
} from "../../offline/backgroundSync";

const getSaleAttributes = (saleDetails) =>
    saleDetails?.attributes || saleDetails?.data?.attributes || {};

const getCustomerAttributes = (customer) =>
    customer?.attributes || customer || {};

const mergeReceiptWithSale = (receipt, saleDetails) => {
    const attributes = getSaleAttributes(saleDetails);
    const receiptCustomer = getCustomerAttributes(receipt?.customer);
    const serverCustomer = getCustomerAttributes(attributes.customer);

    return {
        ...receipt,
        barcode_url: attributes.barcode_url ?? receipt?.barcode_url,
        reference_code: attributes.reference_code ?? receipt?.reference_code,
        customer: {
            ...receiptCustomer,
            ...serverCustomer,
            name: serverCustomer.name || receiptCustomer.name || "",
            identification: serverCustomer.identification || receiptCustomer.identification || "",
            address: serverCustomer.address || receiptCustomer.address || "",
        },
        user_name: attributes.user_name ?? receipt?.user_name,
        numero_comprobante: attributes.numero_comprobante ?? receipt?.numero_comprobante,
        tipo_comprobante: attributes.tipo_comprobante ?? receipt?.tipo_comprobante,
    };
};

const PosMainPage = (props) => {
    const {
        onClickFullScreen,
        posAllProducts,
        customCart,
        frontSetting,
        fetchFrontSetting,
        settings,
        fetchSetting,
        paymentDetails,
        allConfigData,
        fetchBrandClickable,
        posAllTodaySaleOverAllReport,
        fetchHoldLists,
        holdListData,
        config,
    } = props;
    // 1. Agrega los refs
    const brandIdRef = useRef();
    const categoryIdRef = useRef();
    const warehouseIdRef = useRef();
    const registerDetailsRef = useRef();
    const fetchRequestIdRef = useRef(0);
    const syncedWarehouseRef = useRef(null);
    const serverReachableRef = useRef(navigator.onLine);
    // const [play] = useSound('https://s3.amazonaws.com/freecodecamp/drums/Heater-4_1.mp3');
    const [openCalculator, setOpenCalculator] = useState(false);
    const [mobilePane, setMobilePane] = useState("catalog");
    const [quantity, setQuantity] = useState(1);
    const [updateProducts, setUpdateProducts] = useState([]);
    const [isOpenCartItemUpdateModel, setIsOpenCartItemUpdateModel] =
        useState(false);
    const [product, setProduct] = useState(null);
    const [cartProductIds, setCartProductIds] = useState([]);
    const [paymentPrint, setPaymentPrint] = useState({});
    const [cashPayment, setCashPayment] = useState(false);
    const [checkoutProcessing, setCheckoutProcessing] = useState(false);
    const [checkoutStage, setCheckoutStage] = useState("");
    const checkoutProcessingRef = useRef(false);
    const pendingReceiptRef = useRef(false);
    const [modalShowPaymentSlip, setModalShowPaymentSlip] = useState(false);
    const [modalShowCustomer, setModalShowCustomer] = useState(false);
    const [productMsg, _] = useState(0);
    const [brandId, setBrandId] = useState();
    const [categoryId, setCategoryId] = useState();
    const [selectedCustomerOption, setSelectedCustomerOption] = useState(null);
    // Datos con los que se abre el alta de cliente (p. ej. la cédula que se
    // buscó en el POS y no existe todavía).
    const [customerDraft, setCustomerDraft] = useState(null);
    const [selectedOption, setSelectedOption] = useState(null);
    warehouseIdRef.current = selectedOption?.value;
    const [pendingWarehouse, setPendingWarehouse] = useState(null);
    const [updateHolList, setUpdateHoldList] = useState(false);
    const [hold_ref_no, setHold_ref_no] = useState("");
    const [cartItemValue, setCartItemValue] = useState({
        discount: 0,
        tax: 0,
        shipping: 0,
    });
    const [cashPaymentValue, setCashPaymentValue] = useState({
        notes: "",
        payment_status: {
            label: getFormattedMessage("dashboard.recentSales.paid.label"),
            value: 1,
        },
    });
    const [errors, setErrors] = useState({ notes: "" });
    const [tipoComprobanteSri, setTipoComprobanteSri] = useState("");
    const [creditProfile, setCreditProfile] = useState(null);
    const [creditLoading, setCreditLoading] = useState(false);
    const [creditTerms, setCreditTerms] = useState({ payment_terms_days: 0, payment_due_date: dayjs().format('YYYY-MM-DD') });
    const [creditSaleEnabled, setCreditSaleEnabled] = useState(false);
    const [catalogStatus, setCatalogStatus] = useState({
        catalogPreview: false,
        status: navigator.onLine ? "idle" : "checking",
        online: navigator.onLine,
        canRetry: navigator.onLine,
        hasCache: false,
        updatedAt: null,
        itemCount: null,
        pendingSales: 0,
        salesReview: 0,
        salesSyncing: 0,
        syncedSales: 0,
        pendingCustomers: 0,
        customerReview: 0,
        backgroundSyncReady: false,
        backgroundSyncSupported: supportsOfflineBackgroundSync(),
    });
    const [showOfflineSales, setShowOfflineSales] = useState(false);
    // Se incrementa tras cada cobro para refrescar "Ventas del turno".
    // const [searchString, setSearchString] = useState('');
    const [showCloseDetailsModal, setShowCloseDetailsModal] = useState(false);
    const { closeRegisterDetails } = useSelector((state) => state);
    const dispatch = useDispatch();
    const navigate = useNavigate();

    //total Qty on cart item
    const localCart = updateProducts.map((updateQty) =>
        Number(updateQty.quantity)
    );
    const totalQty =
        localCart.length > 0 &&
        localCart.reduce((cart, current) => {
            return cart + current;
        });

    //subtotal on cart item
    const localTotal = updateProducts.map(
        (updateQty) =>
            calculateProductCost(updateQty).toFixed(2) * updateQty.quantity
    );
    const subTotal =
        localTotal.length > 0 &&
        localTotal.reduce((cart, current) => {
            return cart + current;
        });

    const [holdListId, setHoldListValue] = useState({
        referenceNumber: "",
    });

    //grand total on cart item
    const discountTotal = subTotal - cartItemValue.discount;
    const taxTotal = (discountTotal * cartItemValue.tax) / 100;
    const mainTotal = discountTotal + taxTotal;
    const grandTotal = (
        Number(mainTotal) + Number(cartItemValue.shipping)
    ).toFixed(2);

    useEffect(() => {
        if (!paymentDetails?.attributes) return;

        setPaymentPrint((currentPaymentPrint) =>
            mergeReceiptWithSale(currentPaymentPrint, paymentDetails)
        );
    }, [paymentDetails]);

    useEffect(() => {
        setSelectedCustomerOption(
            settings.attributes?.default_customer ? {
                value: Number(settings.attributes.default_customer),
                label: settings.attributes.customer_name,
                es_consumidor_final: true,
            } : null
        );
        // WarehouseDropDown resuelve la selección con la lista vigente.
    }, [settings]);

    useEffect(() => {
        fetchSetting();
        fetchFrontSetting();
        if (navigator.onLine) {
            fetchTodaySaleOverAllReport();
            fetchHoldLists();
        }
    }, []);

    useEffect(() => {
        if (updateHolList === true) {
            fetchHoldLists();
            setUpdateHoldList(false);
        }
    }, [updateHolList]);

    useEffect(() => {
        setUpdateProducts(updateProducts);
    }, [quantity, grandTotal]);

    const handleValidation = () => {
        let errors = {};
        let isValid = true;
        if (
            cashPaymentValue["notes"] &&
            cashPaymentValue["notes"].length > 100
        ) {
            errors["notes"] = "Las notas no pueden superar los 100 caracteres.";
            isValid = false;
        }

        const totalTendered = paymentRows.reduce(
            (sum, row) => sum + (Number(row.amount) || 0),
            0
        );
        const cashTendered = paymentRows.reduce(
            (sum, row) => sum + (row.payment_type?.value === 1 ? (Number(row.amount) || 0) : 0),
            0
        );
        const changeDue = Math.max(0, totalTendered - grandTotal);
        if (changeDue > cashTendered + 0.009) {
            errors["payment"] =
                "El excedente no puede devolverse: el efectivo recibido no alcanza para cubrir el cambio.";
            isValid = false;
        }
        if (paymentRows.some((row) => Number(row.amount) > 0 && !row.payment_type?.value)) {
            errors["payment"] = "Selecciona el método de cada pago ingresado.";
            isValid = false;
        }
        if (paymentRows.some((row) => Number(row.amount) > 0 && Number(row.payment_type?.value) !== 1 && !row.reference?.trim())) {
            errors["payment"] = "Agrega una referencia para los pagos que no son en efectivo.";
            isValid = false;
        }
        const newBalance = Math.max(0, Number(grandTotal) - Math.min(Number(grandTotal), totalTendered));
        const customer = Array.isArray(selectedCustomerOption) ? selectedCustomerOption[0] : selectedCustomerOption;
        if (newBalance > 0 && !creditSaleEnabled) {
            errors["payment"] = "Falta dinero para completar el cobro. Registra el saldo o activa la venta a crédito.";
            isValid = false;
        }
        if (newBalance > 0 && customer?.es_consumidor_final) {
            errors["payment"] = "Consumidor final no puede mantener saldos pendientes. Selecciona un cliente identificado o completa el cobro.";
            isValid = false;
        }
        // Ficha técnica del SRI, numeral 9.10: una factura de más de USD 50
        // debe llevar los datos del comprador.
        if (tipoComprobanteSri === "01" && customer?.es_consumidor_final && Number(grandTotal) > 50) {
            errors["payment"] = "El SRI no permite facturas de más de $50.00 a Consumidor final. Selecciona un cliente con cédula o RUC, o cobra sin factura electrónica.";
            isValid = false;
        }
        if (catalogStatus.online && creditProfile?.credit_enabled && newBalance > Number(creditProfile.available_credit || 0)) {
            errors["payment"] = `El saldo pendiente supera el cupo disponible del cliente ($${Number(creditProfile.available_credit || 0).toFixed(2)}).`;
            isValid = false;
        }
        if (newBalance > 0 && creditSaleEnabled && !creditTerms.payment_due_date) {
            errors["payment"] = "Selecciona la fecha de vencimiento del saldo pendiente.";
            isValid = false;
        }
        setErrors(errors);
        return isValid;
    };

    // 2. Actualiza refs junto con estado
    const setCategory = (item) => {
        if (categoryIdRef.current === item) return;
        ++fetchRequestIdRef.current;
        setCategoryId(item);
        categoryIdRef.current = item;
    };

    const setBrand = (item) => {
        if (brandIdRef.current === item) return;
        ++fetchRequestIdRef.current;
        setBrandId(item);
        brandIdRef.current = item;
    };

    useEffect(() => {
        if (!selectedOption) return;

        const requestId = ++fetchRequestIdRef.current;
        const warehouseId = selectedOption.value;
        const isCurrent = () => requestId === fetchRequestIdRef.current
            && String(warehouseIdRef.current) === String(warehouseId);

        const timer = setTimeout(async () => {
            if (isCurrent()) {
                const warehouseChanged = String(syncedWarehouseRef.current) !== String(warehouseId);
                const refreshCache = warehouseChanged && navigator.onLine;
                if (refreshCache) syncedWarehouseRef.current = warehouseId;

                const result = await fetchBrandClickable(
                    brandIdRef.current,
                    categoryIdRef.current,
                    warehouseId,
                    { refreshCache, isCurrent, previewCache: warehouseChanged }
                );
                if (refreshCache && result?.source !== "network"
                    && String(syncedWarehouseRef.current) === String(warehouseId)) {
                    syncedWarehouseRef.current = null;
                }
            }
        }, String(syncedWarehouseRef.current) !== String(warehouseId) ? 0 : 300);

        return () => {
            clearTimeout(timer);
            // Invalida también las consultas que ya salieron a la API o a
            // IndexedDB, incluso durante el debounce o al salir del POS.
            if (requestId === fetchRequestIdRef.current) ++fetchRequestIdRef.current;
        };
    }, [selectedOption?.value, brandId, categoryId]);

    const syncOfflineCatalog = async () => {
        const warehouseId = warehouseIdRef.current;
        if (!warehouseId || !navigator.onLine) return;
        const requestId = fetchRequestIdRef.current;

        return fetchBrandClickable(
            brandIdRef.current,
            categoryIdRef.current,
            warehouseId,
            {
                refreshCache: true,
                isCurrent: () => requestId === fetchRequestIdRef.current
                    && String(warehouseIdRef.current) === String(warehouseId),
            }
        );
    };

    // Si el servidor rechaza la credencial de sincronización (401), la
    // sincronización la descarta; aquí se pide una nueva y se reintenta una
    // sola vez. Sin esto, una credencial que dejó de existir en el servidor
    // (por ejemplo, tras reemplazar la base de datos) bloqueaba todas las
    // ventas con "Unauthenticated." hasta que venciera, un mes después.
    const runWithFreshCredential = async (syncFn, options) => {
        let result = await syncFn(options).catch(() => null);
        if (result?.authFailed) {
            const fresh = await ensureOfflineSyncCredential().catch(() => null);
            if (fresh?.token) {
                result = await syncFn({ ...options, credential: fresh }).catch(() => null);
            }
            if (!fresh?.token || result?.authFailed) {
                dispatch(addToast({
                    text: "El servidor no aceptó la sesión para sincronizar. Cierra sesión y vuelve a ingresar.",
                    type: toastType.ERROR,
                }));
            }
        }
        return result;
    };

    const syncQueuedSales = async (force = false) => {
        if (!navigator.onLine) return;

        await resetOfflineCsrfFailures().catch(() => null);
        // Ventas bloqueadas por una credencial rechazada vuelven a la cola:
        // el reintento descubre si la credencial sigue sin valer y la renueva.
        await resetOfflineAuthFailures().catch(() => null);

        let credential = await getOfflineSyncCredential().catch(() => null);
        const pendingSales = (await getOfflineSales().catch(() => []))
            .filter((sale) => ["pending", "syncing"].includes(sale.status));
        const queuedCustomers = await getOfflineCustomers().catch(() => []);
        const customersNeedingSync = queuedCustomers.filter((customer) =>
            ["pending", "syncing"].includes(customer.status)
            || (customer.status === "requires_review" && customer.errorCode === "AUTH")
        );
        if (customersNeedingSync.length || pendingSales.length) {
            credential = await ensureOfflineSyncCredential().catch(() => credential);
        }
        if (customersNeedingSync.length) {
            const customerResult = await runWithFreshCredential(syncOfflineCustomers, {
                force,
                credential,
                onReview: (_queuedCustomer, message) => {
                    dispatch(addToast({
                        text: `Un cliente offline requiere revisión: ${message}`,
                        type: toastType.WARNING,
                    }));
                },
            });

            if (customerResult?.credentialMissing) {
                dispatch(addToast({
                    text: "No se pudo preparar la credencial segura para sincronizar clientes.",
                    type: toastType.ERROR,
                }));
                return;
            }
            if (customerResult?.synced) {
                dispatch(addToast({
                    text: `${customerResult.synced} cliente${customerResult.synced === 1 ? "" : "s"} offline sincronizado${customerResult.synced === 1 ? "" : "s"}.`,
                }));
            }
        }

        const result = await runWithFreshCredential(syncOfflineSales, {
            force,
            credential,
            onReview: (_queuedSale, message) => {
                dispatch(addToast({
                    text: `Una venta offline requiere revisión: ${message}`,
                    type: toastType.WARNING,
                }));
            },
        });

        if (result?.credentialMissing) {
            dispatch(addToast({
                text: "No se pudo preparar la credencial segura para sincronizar las ventas.",
                type: toastType.ERROR,
            }));
            return;
        }

        if (result?.synced) {
            dispatch(addToast({
                text: `${result.synced} venta${result.synced === 1 ? "" : "s"} offline sincronizada${result.synced === 1 ? "" : "s"}.`,
            }));
            await syncOfflineCatalog();
        }
    };

    const diagnoseQueuedSale = async (sale) => {
        if (!navigator.onLine) {
            throw new Error("Necesitas conexión para consultar el inventario actual.");
        }
        const credential = await ensureOfflineSyncCredential();
        return diagnoseOfflineSale(sale, { credential });
    };

    useEffect(() => {
        let mounted = true;
        let connectivityRequest = null;
        let connectivityTimer = null;

        const getConnectivityDelay = () => {
            // En estado normal reducimos el consumo del hosting compartido.
            // Durante una caída consultamos más seguido para recuperar el POS.
            const minimum = serverReachableRef.current ? 45000 : 8000;
            const jitter = serverReachableRef.current ? 15000 : 4000;
            return minimum + Math.floor(Math.random() * jitter);
        };

        const inspectLocalCatalog = async (online = navigator.onLine) => {
            const warehouseId = selectedOption?.value;
            if (!warehouseId) {
                if (mounted) setCatalogStatus((current) => ({ ...current, online }));
                return;
            }

            const snapshot = await getOfflineSnapshot("catalog", { warehouseId }).catch(() => null);
            if (!mounted) return;

            setCatalogStatus((current) => ({
                ...current,
                status: online ? (snapshot ? "ready" : "idle") : (snapshot ? "offline" : "unavailable"),
                online,
                catalogPreview: online ? current.catalogPreview : false,
                canRetry: online,
                hasCache: Boolean(snapshot),
                updatedAt: snapshot?.updatedAt || null,
                itemCount: snapshot?.itemCount ?? null,
                warehouseId,
            }));
        };

        const handleCatalogStatus = (event) => {
            if (!mounted) return;
            const detail = event.detail || {};
            if (detail.warehouseId && selectedOption?.value
                && String(detail.warehouseId) !== String(selectedOption.value)) return;
            setCatalogStatus((current) => ({ ...current, ...detail }));
        };

        const handleOfflineSalesStatus = (event) => {
            const detail = event.detail || {};
            setCatalogStatus((current) => ({
                ...current,
                pendingSales: detail.pending ?? current.pendingSales,
                salesReview: detail.review ?? current.salesReview,
                salesSyncing: detail.syncing ?? current.salesSyncing,
                syncedSales: detail.synced ?? current.syncedSales,
            }));
        };

        const handleOfflineCustomersStatus = (event) => {
            const detail = event.detail || {};
            setCatalogStatus((current) => ({
                ...current,
                pendingCustomers: detail.pending ?? current.pendingCustomers,
                customerReview: detail.review ?? current.customerReview,
            }));
        };

        const refreshOfflineSalesStatus = () => {
            getOfflineSales().then((sales) => {
                if (!mounted) return;
                setCatalogStatus((current) => ({
                    ...current,
                    pendingSales: sales.filter((sale) => ["pending", "syncing"].includes(sale.status)).length,
                    salesReview: sales.filter((sale) => sale.status === "requires_review").length,
                    salesSyncing: sales.filter((sale) => sale.status === "syncing").length,
                    syncedSales: sales.filter((sale) => sale.status === "synced").length,
                }));
            }).catch(() => null);
        };

        const refreshOfflineCustomersStatus = () => {
            getOfflineCustomers().then((customers) => {
                if (!mounted) return;
                setCatalogStatus((current) => ({
                    ...current,
                    pendingCustomers: customers.filter((customer) => ["pending", "syncing"].includes(customer.status)).length,
                    customerReview: customers.filter((customer) => customer.status === "requires_review").length,
                }));
            }).catch(() => null);
        };

        const handleServiceWorkerMessage = (event) => {
            if (event.data?.type === "OFFLINE_SALES_STATUS_CHANGED") {
                refreshOfflineSalesStatus();
            }
            if (event.data?.type === "OFFLINE_CUSTOMERS_STATUS_CHANGED") {
                refreshOfflineCustomersStatus();
            }
        };

        const restoreOnlineMode = () => {
            if (serverReachableRef.current) return;
            serverReachableRef.current = true;
            setCatalogStatus((current) => ({ ...current, online: true, status: "syncing" }));
            syncOfflineCatalog();
            fetchTodaySaleOverAllReport();
            fetchHoldLists();
        };

        const markOffline = () => {
            serverReachableRef.current = false;
            inspectLocalCatalog(false);
        };

        const checkServerConnectivity = async () => {
            if (!navigator.onLine) {
                markOffline();
                return;
            }

            connectivityRequest?.abort();
            const request = new AbortController();
            connectivityRequest = request;
            const timeout = window.setTimeout(() => request.abort(), 5000);

            try {
                const response = await fetch("/api/health", {
                    cache: "no-store",
                    credentials: "same-origin",
                    signal: request.signal,
                });

                if (!response.ok) throw new Error("EcuaPos no está disponible");

                if (!serverReachableRef.current) {
                    restoreOnlineMode();
                } else if (mounted) {
                    setCatalogStatus((current) => ({ ...current, online: true, canRetry: true }));
                }
                syncQueuedSales();
            } catch (_) {
                if (mounted && connectivityRequest === request) markOffline();
            } finally {
                window.clearTimeout(timeout);
            }
        };

        const scheduleConnectivityCheck = () => {
            window.clearTimeout(connectivityTimer);
            if (!mounted) return;

            connectivityTimer = window.setTimeout(async () => {
                if (document.visibilityState === "visible") {
                    await checkServerConnectivity();
                }
                scheduleConnectivityCheck();
            }, getConnectivityDelay());
        };

        const triggerConnectivityCheck = async () => {
            window.clearTimeout(connectivityTimer);
            await checkServerConnectivity();
            scheduleConnectivityCheck();
        };

        const handleOnline = () => triggerConnectivityCheck();
        const handleOffline = () => {
            markOffline();
            scheduleConnectivityCheck();
        };
        const handleVisibility = () => {
            if (document.visibilityState === "visible") triggerConnectivityCheck();
        };

        window.addEventListener(OFFLINE_CATALOG_EVENT, handleCatalogStatus);
        window.addEventListener(OFFLINE_SALES_EVENT, handleOfflineSalesStatus);
        window.addEventListener(OFFLINE_CUSTOMERS_EVENT, handleOfflineCustomersStatus);
        window.addEventListener("offline", handleOffline);
        window.addEventListener("online", handleOnline);
        document.addEventListener("visibilitychange", handleVisibility);
        navigator.serviceWorker?.addEventListener("message", handleServiceWorkerMessage);
        inspectLocalCatalog();
        refreshOfflineSalesStatus();
        refreshOfflineCustomersStatus();
        triggerConnectivityCheck();

        return () => {
            mounted = false;
            connectivityRequest?.abort();
            window.clearTimeout(connectivityTimer);
            window.removeEventListener(OFFLINE_CATALOG_EVENT, handleCatalogStatus);
            window.removeEventListener(OFFLINE_SALES_EVENT, handleOfflineSalesStatus);
            window.removeEventListener(OFFLINE_CUSTOMERS_EVENT, handleOfflineCustomersStatus);
            window.removeEventListener("offline", handleOffline);
            window.removeEventListener("online", handleOnline);
            document.removeEventListener("visibilitychange", handleVisibility);
            navigator.serviceWorker?.removeEventListener("message", handleServiceWorkerMessage);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedOption?.value]);

    useEffect(() => {
        if (!selectedOption?.value) return;
        let active = true;

        const prepareBackgroundSync = async () => {
            const storedCredential = await getOfflineSyncCredential().catch(() => null);
            if (!catalogStatus.online) return storedCredential;
            return ensureOfflineSyncCredential();
        };

        prepareBackgroundSync()
            .then(async (credential) => {
                if (!active) return;
                const validCredential = credential?.token
                    && Number(credential.version || 0) >= 2
                    && new Date(credential.expires_at).getTime() > Date.now();
                setCatalogStatus((current) => ({
                    ...current,
                    backgroundSyncReady: Boolean(validCredential && supportsOfflineBackgroundSync()),
                }));
                if (validCredential) await requestOfflineSaleBackgroundSync().catch(() => null);
            })
            .catch(() => {
                if (active) {
                    setCatalogStatus((current) => ({ ...current, backgroundSyncReady: false }));
                }
            });

        return () => { active = false; };
    }, [catalogStatus.online, selectedOption?.value]);

    useEffect(() => {
        setUpdateProducts([]);
        setCartProductIds([]);
    }, [selectedOption?.value]);


    const handleWarehouseChangeWithConfirm = (newOption) => {
        if (updateProducts.length > 0 && newOption?.value !== selectedOption?.value) {
            setPendingWarehouse(newOption);
            return;
        }
        setSelectedOption(newOption);
    };

    const confirmWarehouseChange = () => {
        setSelectedOption(pendingWarehouse);
        setPendingWarehouse(null);
    };

    const onChangeInput = (e) => {
        e.preventDefault();
        setCashPaymentValue((inputs) => ({
            ...inputs,
            [e.target.name]: e.target.value,
        }));
    };

    // payment type dropdown functionality
    const paymentTypeFilterOptions = getFormattedOptions(paymentMethodOptions);
    const paymentTypeDefaultValue = paymentTypeFilterOptions.map((option) => {
        return {
            value: option.id,
            label: option.name,
        };
    });

    // Filas de pago del POS: por defecto una sola (efectivo), pero se
    // pueden agregar más para dividir el cobro entre varias formas de
    // pago (ej. $20 efectivo + $10 transferencia).
    const [paymentRows, setPaymentRows] = useState([
        { id: 1, amount: "", payment_type: paymentTypeDefaultValue[0], reference: "" },
    ]);

    const onAddPaymentRow = () => {
        setPaymentRows((prev) => {
            const totalPagado = prev.reduce(
                (sum, row) => sum + (Number(row.amount) || 0),
                0
            );
            const saldoRestante = Math.max(0, grandTotal - totalPagado);
            return [
                ...prev,
                {
                    id: Date.now(),
                    amount: saldoRestante > 0 ? saldoRestante.toFixed(2) : "",
                    payment_type: paymentTypeDefaultValue[0],
                    reference: "",
                },
            ];
        });
    };

    const onRemovePaymentRow = (id) => {
        setPaymentRows((prev) =>
            prev.length > 1 ? prev.filter((row) => row.id !== id) : prev
        );
    };

    const onPaymentRowAmountChange = (id, value) => {
        setErrors((current) => ({...current, payment: ""}));
        setPaymentRows((prev) =>
            prev.map((row) => (row.id === id ? { ...row, amount: value } : row))
        );
    };

    const onPaymentRowTypeChange = (id, obj) => {
        setErrors((current) => ({...current, payment: ""}));
        setPaymentRows((prev) =>
            prev.map((row) =>
                row.id === id ? { ...row, payment_type: obj } : row
            )
        );
    };

    const onPaymentRowReferenceChange = (id, value) => {
        setErrors((current) => ({...current, payment: ""}));
        setPaymentRows((prev) =>
            prev.map((row) => row.id === id ? {...row, reference: value} : row)
        );
    };

    const onChangeCart = (event) => {
        const { value } = event.target;
        // check if value includes a decimal point
        if (value.match(/\./g)) {
            const [, decimal] = value.split(".");
            // restrict value to only 2 decimal places
            if (decimal?.length > 2) {
                // do nothing
                return;
            }
        }
        setCartItemValue((inputs) => ({
            ...inputs,
            [event.target.name]: value,
        }));
    };

    const onChangeTaxCart = (event) => {
        const min = 0;
        const max = 100;
        const { value } = event.target;
        const values = Math.max(min, Math.min(max, Number(value)));
        // check if value includes a decimal point
        if (value.match(/\./g)) {
            const [, decimal] = value.split(".");
            // restrict value to only 2 decimal places
            if (decimal?.length > 2) {
                // do nothing
                return;
            }
        }
        setCartItemValue((inputs) => ({
            ...inputs,
            [event.target.name]: values,
        }));
    };

    //payment slip model onchange
    const handleCashPayment = () => {
        setCashPaymentValue({
            notes: "",
            payment_status: {
                label: getFormattedMessage("dashboard.recentSales.paid.label"),
                value: 1,
            },
        });
        setErrors({notes: ""});
        setCreditSaleEnabled(false);
        setCashPayment(!cashPayment);
    };

    // El botón "Pagar" (PaymentButton.js) abre el modal llamando a
    // setCashPayment(true) directo, sin pasar por handleCashPayment -- por
    // eso el precargado va acá, mirando el valor de cashPayment en sí, así
    // agarra la apertura sin importar desde dónde se dispare.
    useEffect(() => {
        if (cashPayment) {
            setPaymentRows([
                {
                    id: Date.now(),
                    amount: grandTotal,
                    payment_type: paymentTypeDefaultValue[0],
                    reference: "",
                },
            ]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cashPayment]);

    useEffect(() => {
        if (!cashPayment || !catalogStatus.online) {
            setCreditProfile(null);
            return;
        }
        const customer = Array.isArray(selectedCustomerOption) ? selectedCustomerOption[0] : selectedCustomerOption;
        if (!customer?.value) return;
        let active = true;
        setCreditLoading(true);
        apiConfig.get(`customers/${customer.value}/credit-profile`)
            .then((response) => {
                if (!active) return;
                const profile = response.data.data;
                setCreditProfile(profile);
                const days = Number(profile.default_payment_terms_days || 0);
                setCreditTerms({ payment_terms_days: days, payment_due_date: dayjs().add(days, 'day').format('YYYY-MM-DD') });
            })
            .catch(() => active && setCreditProfile(null))
            .finally(() => active && setCreditLoading(false));
        return () => { active = false; };
    }, [cashPayment, selectedCustomerOption, catalogStatus.online]);

    //product details model onChange
    const openProductDetailModal = () => {
        setIsOpenCartItemUpdateModel(!isOpenCartItemUpdateModel);
    };

    //product details model updated value
    const onClickUpdateItemInCart = (item) => {
        setProduct(item);
        setIsOpenCartItemUpdateModel(true);
    };

    const onProductUpdateInCart = (updatedProduct) => {
        setUpdateProducts((products) => products.map((item) =>
            item.id === updatedProduct.id ? {...updatedProduct} : item
        ));
    };

    //updated Qty function
    const updatedQty = (qty) => {
        setQuantity(qty);
    };

    const updateCart = (cartProducts) => {
        setUpdateProducts(cartProducts);
    };

    //cart item delete
    const onDeleteCartItem = (productId) => {
        const existingCart = updateProducts.filter((e) => e.id !== productId);
        updateCart(existingCart);
    };

    const removeCartItemWithUndo = (cartItem) => {
        const removedIndex = updateProducts.findIndex((item) => item.id === cartItem.id);
        onDeleteCartItem(cartItem.id);
        dispatch(addToast({
            text: `${cartItem.name} se quitó del pedido.`,
            type: toastType.WARNING,
            actionLabel: "Deshacer",
            onAction: () => setUpdateProducts((current) => {
                if (current.some((item) => item.id === cartItem.id)) return current;
                const restored = [...current];
                restored.splice(Math.max(0, removedIndex), 0, cartItem);
                return restored;
            }),
        }));
    };

    //product add to cart function
    const addToCarts = useCallback((items) => setUpdateProducts(items), []);

    // create customer model
    const customerModel = (val, draft = null) => {
        setCustomerDraft(draft);
        setModalShowCustomer(val);
    };

    //prepare data for print Model
    const preparePrintData = () => {
        const totalPaid = paymentRows.reduce(
            (sum, row) => sum + (Number(row.amount) || 0),
            0
        );
        const formValue = {
            products: updateProducts,
            discount: cartItemValue.discount ? cartItemValue.discount : 0,
            tax: cartItemValue.tax ? cartItemValue.tax : 0,
            cartItemPrint: cartItemValue,
            taxTotal: taxTotal,
            grandTotal: grandTotal,
            shipping: cartItemValue.shipping,
            subTotal: subTotal,
            frontSetting: frontSetting,
            customer_name: selectedCustomerOption,
            settings: settings,
            note: cashPaymentValue.notes,
            changeReturn: Math.max(0, totalPaid - grandTotal),
            payment_status: totalPaidAmount(),
            tipoComprobanteSri: tipoComprobanteSri,
            payments: paymentRows
                .filter((row) => Number(row.amount) > 0)
                .map((row) => ({label: row.payment_type?.label, amount: Number(row.amount), reference: row.reference || null})),
        };
        return formValue;
    };

    //prepare data for payment api
    const prepareData = (updateProducts) => {
        const customerOption = Array.isArray(selectedCustomerOption)
            ? selectedCustomerOption[0]
            : selectedCustomerOption;
        const offlineCustomerUuid = customerOption?.offlineCustomerUuid || null;
        const formValue = {
            date: dayjs(new Date()).format("YYYY-MM-DD"),
            customer_id: offlineCustomerUuid ? null : customerOption?.value,
            offline_customer_uuid: offlineCustomerUuid,
            warehouse_id:
                selectedOption && selectedOption[0]
                    ? selectedOption[0].value
                    : selectedOption && selectedOption.value,
            sale_items: updateProducts,
            grand_total: grandTotal,
            // Se manda el desglose completo de formas de pago -- el backend
            // ya sabe calcular paid_amount y payment_status a partir de esto
            // (ver SaleRepository::storeSale). payment_type queda como el de
            // la primera fila, solo por compatibilidad con pantallas viejas
            // que todavía leen ese campo suelto.
            payments: paymentRows
                .filter((row) => Number(row.amount) > 0)
                .map((row) => ({
                    amount: Number(row.amount),
                    payment_type: row.payment_type?.value,
                    reference: row.reference?.trim() || null,
                })),
            payment_type: paymentRows[0]?.payment_type?.value,
            discount: cartItemValue.discount,
            shipping: cartItemValue.shipping,
            tax_rate: cartItemValue.tax,
            note: cashPaymentValue.notes,
            status: 1,
            hold_ref_no: hold_ref_no,
            payment_status: totalPaidAmount(),
            payment_terms_days: Number(creditTerms.payment_terms_days || 0),
            payment_due_date: totalPaidAmount() === 1 ? null : creditTerms.payment_due_date,
        };
        return formValue;
    };

    // Estado de pago calculado a partir de las filas -- nunca se confía
    // en una selección manual, se deduce de cuánto se cargó realmente.
    const totalPaidAmount = () => {
        const totalPaid = paymentRows.reduce(
            (sum, row) => sum + (Number(row.amount) || 0),
            0
        );
        if (totalPaid <= 0) return 2; // No pagado
        if (totalPaid >= grandTotal) return 1; // Pagado
        return 3; // Pago parcial
    };

    const finishCheckout = () => {
        setCashPayment(false);
        setTipoComprobanteSri("");
        setCartItemValue({ discount: 0, tax: 0, shipping: 0 });
        setCashPaymentValue({
            notes: "",
            payment_status: {
                label: getFormattedMessage("dashboard.recentSales.paid.label"),
                value: 1,
            },
        });
        resetPaymentRows();
        setCreditProfile(null);
        setCreditSaleEnabled(false);
        setCreditTerms({ payment_terms_days: 0, payment_due_date: dayjs().format('YYYY-MM-DD') });
        setCartProductIds("");
    };

    const handlePaymentModalExited = () => {
        if (!pendingReceiptRef.current) return;

        pendingReceiptRef.current = false;
        setModalShowPaymentSlip(true);
    };

    const saveLocalFirstCheckout = async (payload, receipt, sriType) => {
        try {
            setCheckoutStage("Protegiendo la venta en este dispositivo…");
            const queuedSale = await enqueueOfflineSale(payload, receipt, sriType);
            const reservedSnapshot = await reserveOfflineCatalogStock(
                payload.warehouse_id,
                payload.sale_items
            ).catch(() => null);

            // Reflejar de inmediato la reserva local. No volvemos a consultar
            // el catálogo del servidor hasta saber si este UUID quedó
            // confirmado; hacerlo antes podría reponer visualmente el stock
            // que la venta protegida acaba de reservar.
            if (reservedSnapshot?.payload) {
                dispatch({
                    type: productActionType.FETCH_BRAND_CLICKABLE,
                    payload: filterCatalogProducts(reservedSnapshot.payload, { brandId, categoryId }),
                });
            }

            let serverSale = null;
            if (catalogStatus.online && !payload.offline_customer_uuid) {
                setCheckoutStage("Confirmando el cobro con el servidor…");
                const credential = await ensureOfflineSyncCredential().catch(() => null);
                if (credential?.token) {
                    await runWithFreshCredential(syncOfflineSales, {
                        force: true,
                        credential,
                        onlyClientUuid: queuedSale.clientUuid,
                        onSynced: (createdSale, syncedQueueItem) => {
                            if (syncedQueueItem.clientUuid === queuedSale.clientUuid) {
                                serverSale = createdSale;
                            }
                        },
                        onReview: (_queuedSale, message) => {
                            dispatch(addToast({
                                text: `La venta quedó protegida, pero requiere revisión: ${message}`,
                                type: toastType.WARNING,
                            }));
                        },
                    }).catch(() => null);

                    // Si otra sincronización ya tenía el lock, recuperamos el
                    // resultado persistido en lugar de reenviar el checkout.
                    const storedSale = await getOfflineSale(queuedSale.clientUuid).catch(() => null);
                    serverSale = serverSale || storedSale?.serverSale || null;
                }
            }

            const localReference = `OFF-${queuedSale.clientUuid.slice(0, 8).toUpperCase()}`;
            const customer = { name: selectedCustomerOption?.label || "Consumidor final" };
            const provisionalReceipt = {
                ...receipt,
                reference_code: localReference,
                offline_pending: true,
                customer,
                user_name: "Pendiente de sincronización",
                tipoComprobanteSri: "",
            };

            dispatch({
                type: posCashPaymentActionType.POS_CASH_PAYMENT,
                payload: serverSale || {
                    attributes: {
                        reference_code: localReference,
                        customer,
                        payments: payload.payments,
                        created_offline: true,
                    },
                },
            });
            setPaymentPrint(serverSale
                ? mergeReceiptWithSale(receipt, serverSale)
                : provisionalReceipt);
            setUpdateProducts([]);
            // El ticket se abre desde onExited del modal de cobro. Así no se
            // superponen ambos modales mientras termina la animación de cierre.
            pendingReceiptRef.current = true;
            setCheckoutStage(serverSale ? "Venta confirmada" : "Venta guardada para sincronizar");
            dispatch(addToast({
                text: serverSale
                    ? "Venta registrada y confirmada correctamente."
                    : "Venta protegida en este dispositivo. EcuaPos confirmará el mismo cobro al recuperar conexión.",
            }));
            // La venta y su ticket ya están preparados. Estas tareas auxiliares
            // no deben retrasar finishCheckout/onExited ni convertir un fallo
            // de actualización en un supuesto error del cobro confirmado.
            void Promise.resolve().then(() => requestOfflineSaleBackgroundSync()).catch(() => null);
            if (serverSale) {
                void Promise.resolve().then(() => syncOfflineCatalog()).catch(() => null);
            }
            return true;
        } catch (_) {
            dispatch(addToast({
                text: "No se pudo proteger la venta en este dispositivo. El carrito se conservó y no se envió ningún cobro.",
                type: toastType.ERROR,
            }));
            return false;
        }
    };

    //cash payment method
    const processCashPayment = async () => {
        if (!handleValidation()) return;

        const payload = prepareData(updateProducts);
        // Identidad estable para todo el ciclo del cobro. También se envía en
        // ventas online: si el servidor confirma pero la respuesta se pierde,
        // la copia que queda en IndexedDB reutiliza este UUID y el backend la
        // reconoce como la misma venta.
        payload.client_uuid = createClientUuid();
        const receipt = preparePrintData();
        const requestedSriType = tipoComprobanteSri;
        payload.requested_electronic_document = requestedSriType || null;

        // Toda venta usa el mismo outbox local, haya o no conexión. El
        // servidor deja de ser el primer paso y pasa a confirmar un UUID que
        // ya quedó guardado de forma durable en el dispositivo.
        if (await saveLocalFirstCheckout(payload, receipt, requestedSriType)) finishCheckout();
    };

    const onCashPayment = async (event) => {
        event.preventDefault();
        if (checkoutProcessingRef.current) return;

        checkoutProcessingRef.current = true;
        setCheckoutProcessing(true);
        setCheckoutStage("Validando el cobro…");
        try {
            await processCashPayment();
        } finally {
            checkoutProcessingRef.current = false;
            setCheckoutProcessing(false);
            setCheckoutStage("");
        }
    };

    const printPaymentReceiptPdf = () => {
        document.getElementById("printReceipt").click();
    };

    const printRegisterDetails = () => {
        document.getElementById("printRegisterDetailsId").click();
    };

    const handleRegisterDetailsPrint = useReactToPrint({
        content: () => registerDetailsRef.current,
    });

    //Register details  slip
    const loadRegisterDetailsPrint = () => {
        return (
            <div className="d-none">
                <button
                    id="printRegisterDetailsId"
                    onClick={handleRegisterDetailsPrint}
                >
                    Print this out!
                </button>
                <PrintRegisterDetailsData
                    ref={registerDetailsRef}
                    allConfigData={allConfigData}
                    frontSetting={frontSetting}
                    posAllTodaySaleOverAllReport={posAllTodaySaleOverAllReport}
                    updateProducts={paymentPrint}
                    closeRegisterDetails={closeRegisterDetails}
                />
            </div>
        );
    };


    const resetPaymentRows = () => {
        setPaymentRows([
            { id: Date.now(), amount: "", payment_type: paymentTypeDefaultValue[0], reference: "" },
        ]);
    };

    const loadPaymentSlip = () => {
        return (
            // ✅ Ya no necesita "d-none" ni el botón oculto
            <PaymentSlipModal
                setPaymentValue={resetPaymentRows}
                setModalShowPaymentSlip={setModalShowPaymentSlip}
                settings={settings}
                frontSetting={frontSetting}
                modalShowPaymentSlip={modalShowPaymentSlip}
                allConfigData={allConfigData}
                paymentDetails={paymentDetails}
                updateProducts={paymentPrint}
                payments={paymentRows
                    .filter((row) => Number(row.amount) > 0)
                    .map((row) => ({
                        label: row.payment_type?.label,
                        amount: Number(row.amount),
                        reference: row.reference || null,
                    }))}
                paymentTypeDefaultValue={paymentTypeDefaultValue}
                tipoComprobanteSri={paymentPrint?.tipoComprobanteSri || ""}
            />
        );
    };

    const [lgShow, setLgShow] = useState(false);
    const [holdShow, setHoldShow] = useState(false);

    const onClickDetailsModel = (isDetails = null) => {
        setLgShow(true);
    };

    const onClickHoldModel = (isDetails = null) => {
        setHoldShow(true);
    };

    const handleClickCloseRegister = () => {
        if (!catalogStatus.online || catalogStatus.pendingSales > 0 || catalogStatus.salesReview > 0) {
            dispatch(addToast({
                text: !catalogStatus.online
                    ? "Conecta y sincroniza las ventas pendientes antes de cerrar la caja."
                    : "Revisa las ventas offline pendientes antes de cerrar la caja.",
                type: toastType.WARNING,
            }));
            if (catalogStatus.pendingSales > 0 || catalogStatus.salesReview > 0) {
                setShowOfflineSales(true);
            }
            return;
        }
        dispatch(getAllRegisterDetailsAction());
        setShowCloseDetailsModal(true);
    };

    const handleCloseRegisterDetails = async (data) => {
        if (data.cash_in_hand_while_closing.toString().trim()?.length === 0) {
            dispatch(
                addToast({
                    text: getFormattedMessage(
                        "pos.cclose-register.enter-total-cash.message"
                    ),
                    type: toastType.ERROR,
                })
            );
            return false;
        } else {
            try {
                await dispatch(closeRegisterAction(data, navigate));
                setShowCloseDetailsModal(false);
                return true;
            } catch (error) {
                return false;
            }
        }
    };

    return (
        <Container className="pos-screen pos-v2 px-3" fluid>
            <TabTitle title="POS" />
            {/* {loadPrintBlock()} */}
            {loadPaymentSlip()}
            {loadRegisterDetailsPrint()}
            <Row className="pos-workspace g-3">
                <TopProgressBar />
                <Col
                    lg={8}
                    xxl={8}
                    className={`pos-catalog-panel pos-right-scs ${mobilePane === "catalog" ? "pos-mobile-pane-active" : ""}`}
                >
                    <div className="pos-catalog-toolbar my-3">
                        <div className="pos-screen-heading">
                            <span className="pos-eyebrow">EcuaPos</span>
                            <h1>Catálogo</h1>
                        </div>
                        <div className="pos-catalog-actions d-sm-flex align-items-center">
                            <ProductSearchbar
                                catalogPreview={catalogStatus.catalogPreview}
                                customCart={customCart}
                                setUpdateProducts={setUpdateProducts}
                                updateProducts={updateProducts}
                            />
                            <HeaderAllButton
                                holdListData={holdListData}
                                goToHoldScreen={onClickHoldModel}
                                goToDetailScreen={onClickDetailsModel}
                                onClickFullScreen={onClickFullScreen}
                                opneCalculator={openCalculator}
                                setOpneCalculator={setOpenCalculator}
                                handleClickCloseRegister={handleClickCloseRegister}
                            />
                        </div>
                    </div>
                    <OfflineCatalogStatus
                        status={catalogStatus}
                        onSync={async () => {
                            await syncQueuedSales(true);
                            await requestOfflineSaleBackgroundSync().catch(() => null);
                            await syncOfflineCatalog();
                        }}
                        onOpenSales={() => setShowOfflineSales(true)}
                    />
                    <div className="right-content custom-card pos-catalog-card mb-3">
                        <div className="pos-category-strip px-3 pt-3">
                            <Category
                                setCategory={setCategory}
                                categoryId={categoryId}
                                brandId={brandId}
                                selectedOption={selectedOption}
                            />
                        </div>
                        <Product
                            catalogPreview={catalogStatus.catalogPreview}
                            cartProducts={updateProducts}
                            updateCart={addToCarts}
                            customCart={customCart}
                            setCartProductIds={setCartProductIds}
                            cartProductIds={cartProductIds}
                            settings={settings}
                            productMsg={productMsg}
                            selectedOption={selectedOption}
                            brandId={brandId}
                            categoryId={categoryId}
                        />
                    </div>
                </Col>
                <Col
                    lg={4}
                    xxl={4}
                    className={`pos-order-panel pos-left-scs ${mobilePane === "cart" ? "pos-mobile-pane-active" : ""}`}
                >
                    <div className="pos-context-card">
                        <PosHeader
                            setSelectedCustomerOption={setSelectedCustomerOption}
                            selectedCustomerOption={selectedCustomerOption}
                            setSelectedOption={handleWarehouseChangeWithConfirm}
                            selectedOption={selectedOption}
                            customerModel={customerModel}
                            offlineMode={!catalogStatus.online}
                        />
                    </div>
                    <div className="left-content custom-card pos-order-card mb-3 p-3">
                        <div className="main-table overflow-auto">
                            <Table className="mb-0">
                                <thead className="position-sticky top-0">
                                    <tr>
                                        <th>
                                            {getFormattedMessage(
                                                "pos-product.title"
                                            )}
                                        </th>
                                        <th
                                            className={
                                                updateProducts &&
                                                    updateProducts.length
                                                    ? "text-center"
                                                    : ""
                                            }
                                        >
                                            {getFormattedMessage(
                                                "pos-qty.title"
                                            )}
                                        </th>
                                        <th>
                                            {getFormattedMessage(
                                                "pos-price.title"
                                            )}
                                        </th>
                                        <th colSpan="2">
                                            {getFormattedMessage(
                                                "pos-sub-total.title"
                                            )}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="border-0">
                                    {updateProducts && updateProducts.length ? (
                                        updateProducts.map(
                                            (updateProduct, index) => {
                                                return (
                                                    <ProductCartList
                                                        singleProduct={
                                                            updateProduct
                                                        }
                                                        key={updateProduct.id}
                                                        index={index}
                                                        posAllProducts={
                                                            posAllProducts
                                                        }
                                                        onClickUpdateItemInCart={
                                                            onClickUpdateItemInCart
                                                        }
                                                        updatedQty={updatedQty}
                                                        onRequestDeleteCartItem={
                                                            removeCartItemWithUndo
                                                        }
                                                        quantity={quantity}
                                                        frontSetting={
                                                            frontSetting
                                                        }
                                                        allConfigData={
                                                            allConfigData
                                                        }
                                                        setUpdateProducts={
                                                            setUpdateProducts
                                                        }
                                                    />
                                                );
                                            }
                                        )
                                    ) : (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="pos-cart-empty"
                                            >
                                                <i className="bi bi-upc-scan" aria-hidden="true" />
                                                <strong>Tu pedido está vacío</strong>
                                                <span>Busca, escanea o selecciona un producto para comenzar.</span>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </Table>
                        </div>
                        <CartItemMainCalculation
                            totalQty={totalQty}
                            subTotal={subTotal}
                            grandTotal={grandTotal}
                            cartItemValue={cartItemValue}
                            onChangeCart={onChangeCart}
                            allConfigData={allConfigData}
                            frontSetting={frontSetting}
                            onChangeTaxCart={onChangeTaxCart}
                        />
                        <PaymentButton
                            updateProducts={updateProducts}
                            updateCart={addToCarts}
                            setUpdateProducts={setUpdateProducts}
                            setCartItemValue={setCartItemValue}
                            setCashPayment={setCashPayment}
                            cartItemValue={cartItemValue}
                            grandTotal={grandTotal}
                            subTotal={subTotal}
                            selectedOption={selectedOption}
                            cashPaymentValue={cashPaymentValue}
                            holdListId={holdListId}
                            setHoldListValue={setHoldListValue}
                            selectedCustomerOption={selectedCustomerOption}
                            setUpdateHoldList={setUpdateHoldList}
                            offlineMode={!catalogStatus.online}
                        />
                    </div>
                </Col>
            </Row>
            <nav className="pos-mobile-bottom-nav" aria-label="Navegación del punto de venta">
                <button
                    type="button"
                    className={mobilePane === "catalog" ? "active" : ""}
                    onClick={() => setMobilePane("catalog")}
                    aria-pressed={mobilePane === "catalog"}
                >
                    <i className="bi bi-grid" aria-hidden="true" />
                    <span>Productos</span>
                </button>
                <button
                    type="button"
                    className={mobilePane === "cart" ? "active" : ""}
                    onClick={() => setMobilePane("cart")}
                    aria-pressed={mobilePane === "cart"}
                >
                    <span className="pos-mobile-cart-icon">
                        <i className="bi bi-cart3" aria-hidden="true" />
                        {updateProducts.length > 0 && (
                            <span className="pos-mobile-cart-badge">{updateProducts.length}</span>
                        )}
                    </span>
                    <span>Carrito</span>
                </button>
            </nav>
            {isOpenCartItemUpdateModel && (
                <ProductDetailsModel
                    openProductDetailModal={openProductDetailModal}
                    productModelId={product.id}
                    onProductUpdateInCart={onProductUpdateInCart}
                    cartProduct={product}
                    isOpenCartItemUpdateModel={isOpenCartItemUpdateModel}
                    frontSetting={frontSetting}
                    canOverridePrice={Array.isArray(config) && config.includes('override_pos_price')}
                />
            )}
            <CashPaymentModel
                    cashPayment={cashPayment}
                    totalQty={totalQty}
                    cartItemValue={cartItemValue}
                    onChangeInput={onChangeInput}
                    cashPaymentValue={cashPaymentValue}
                    allConfigData={allConfigData}
                    subTotal={subTotal}
                    grandTotal={grandTotal}
                    onCashPayment={onCashPayment}
                    taxTotal={taxTotal}
                    handleCashPayment={handleCashPayment}
                    settings={settings}
                    errors={errors}
                    paymentTypeFilterOptions={paymentTypeFilterOptions}
                    paymentRows={paymentRows}
                    onAddPaymentRow={onAddPaymentRow}
                    onRemovePaymentRow={onRemovePaymentRow}
                    onPaymentRowAmountChange={onPaymentRowAmountChange}
                    onPaymentRowTypeChange={onPaymentRowTypeChange}
                    onPaymentRowReferenceChange={onPaymentRowReferenceChange}
                    tipoComprobanteSri={tipoComprobanteSri}
                    onTipoComprobanteChange={setTipoComprobanteSri}
                    offlineMode={!catalogStatus.online}
                    selectedCustomer={Array.isArray(selectedCustomerOption) ? selectedCustomerOption[0] : selectedCustomerOption}
                    creditProfile={creditProfile}
                    creditLoading={creditLoading}
                    creditTerms={creditTerms}
                    onCreditTermsChange={setCreditTerms}
                    creditSaleEnabled={creditSaleEnabled}
                    onCreditSaleEnabledChange={setCreditSaleEnabled}
                    processing={checkoutProcessing}
                    processingLabel={checkoutStage}
                    onExited={handlePaymentModalExited}
                />
            {lgShow && (
                <RegisterDetailsModel
                    printRegisterDetails={printRegisterDetails}
                    frontSetting={frontSetting}
                    lgShow={lgShow}
                    setLgShow={setLgShow}
                />
            )}
            {holdShow && (
                <HoldListModal
                    setUpdateHoldList={setUpdateHoldList}
                    setCartItemValue={setCartItemValue}
                    setUpdateProducts={setUpdateProducts}
                    updateProduct={updateProducts}
                    printRegisterDetails={printRegisterDetails}
                    frontSetting={frontSetting}
                    holdListData={holdListData}
                    setHold_ref_no={setHold_ref_no}
                    holdShow={holdShow}
                    setHoldShow={setHoldShow}
                    addCart={addToCarts}
                    updateCart={updateCart}
                    setSelectedCustomerOption={setSelectedCustomerOption}
                    setSelectedOption={setSelectedOption}
                />
            )}
            {modalShowCustomer && (
                <CustomerForm
                    show={modalShowCustomer}
                    hide={setModalShowCustomer}
                    offlineMode={!catalogStatus.online}
                    initialValues={customerDraft}
                    onCustomerCreated={setSelectedCustomerOption}
                />
            )}
            <PosCloseRegisterDetailsModel
                showCloseDetailsModal={showCloseDetailsModal}
                handleCloseRegisterDetails={handleCloseRegisterDetails}
                setShowCloseDetailsModal={setShowCloseDetailsModal}
            />
            <OfflineSalesModal
                show={showOfflineSales}
                onHide={() => setShowOfflineSales(false)}
                onRetry={syncQueuedSales}
                onDiagnose={diagnoseQueuedSale}
                onDiscard={syncOfflineCatalog}
                online={catalogStatus.online}
            />
            <Modal show={Boolean(pendingWarehouse)} onHide={() => setPendingWarehouse(null)} centered className="pos-modal pos-confirmation-modal">
                <Modal.Header closeButton>
                    <div>
                        <span className="pos-confirmation-modal__eyebrow">CAMBIO DE ALMACÉN</span>
                        <Modal.Title>¿Cambiar a {pendingWarehouse?.label}?</Modal.Title>
                    </div>
                </Modal.Header>
                <Modal.Body>
                    <div className="pos-confirmation-modal__warning"><i className="bi bi-cart-x" /><div><strong>El pedido actual se vaciará</strong><p>Se quitarán {updateProducts.length} productos con un total de {frontSetting.value?.currency_symbol || '$'}{Number(grandTotal).toFixed(2)}.</p></div></div>
                    <p className="mb-0">Si necesitas conservarlo, cancela y usa la opción <strong>Retener venta</strong> antes de cambiar de almacén.</p>
                </Modal.Body>
                <Modal.Footer>
                    <Button variant="light" onClick={() => setPendingWarehouse(null)}>Conservar pedido</Button>
                    <Button variant="danger" onClick={confirmWarehouseChange}>Cambiar y vaciar</Button>
                </Modal.Footer>
            </Modal>
        </Container>
    );
};

const mapStateToProps = () => {
    const selectCart = createCartSelector(prepareCartArray);
    return (state) => {
        const {
            posAllProducts,
            frontSetting,
            settings,
            cashPayment,
            allConfigData,
            posAllTodaySaleOverAllReport,
            holdListData,
            config,
        } = state;
        return {
            holdListData,
            posAllProducts,
            frontSetting,
            settings,
            paymentDetails: cashPayment,
            customCart: selectCart(posAllProducts),
            allConfigData,
            posAllTodaySaleOverAllReport,
            config,
        };
    };
};

export default connect(mapStateToProps, {
    fetchSetting,
    fetchFrontSetting,
    posSearchNameProduct,
    posSearchCodeProduct,
    posAllProduct,
    fetchBrandClickable,
    fetchHoldLists,
})(PosMainPage);
