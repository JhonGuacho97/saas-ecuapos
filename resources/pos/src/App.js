import React, { useCallback, useEffect, useRef, useState } from "react";
import { Route, useLocation, Navigate, Routes } from "react-router-dom";
import "../../pos/src/assets/sass/style.react.scss";
import { useDispatch, useSelector } from "react-redux";
import { IntlProvider } from "react-intl";
import { settingsKey, toastType, Tokens } from "./constants";
import Toasts from "./shared/toast/Toasts";
import { fetchFrontSetting } from "./store/action/frontSettingAction";
import { fetchConfig } from "./store/action/configAction";
import { fetchMyStores } from "./store/action/storeAction";
import { addToast } from "./store/action/toastAction";
import { addRTLSupport, getDefaultRouteForPermissions } from "./shared/sharedMethod";
import Login from "./components/auth/Login";
import ResetPassword from "./components/auth/ResetPassword";
import ForgotPassword from "./components/auth/ForgotPassword";
import Onboarding from "./components/auth/Onboarding";
import AdminApp from "./AdminApp";
import TopProgressBar from "./shared/components/loaders/TopProgressBar";
import SuperAdminApp from "./components/superAdmin";
import SubscriptionAccessGate from "./components/subscription/SubscriptionAccessGate";
import ReadOnlyBanner from "./components/subscription/ReadOnlyBanner";
import apiConfig from "./config/apiConfig";
import useLanguage from "./hooks/useLanguage";

const SAAS_OFFLINE_LEASE_KEY = 'ecuapos_saas_offline_lease';

function App() {
    const dispatch = useDispatch();
    const location = useLocation();
    const token = localStorage.getItem(Tokens.ADMIN);
    const isSuperAdmin = localStorage.getItem(Tokens.IS_SUPER_ADMIN) === "true";
    const { config } = useSelector((state) => state);
    const [saasAccess, setSaasAccess] = useState(token && !isSuperAdmin ? null : { can_access: true });

    // ─── Idioma ───────────────────────────────────────────────────────────────
    const { messages, updatedLanguage, selectedLanguage } = useLanguage();
    const activeLanguage = updatedLanguage ?? selectedLanguage;
    const intlLocale = { sp: 'es', cn: 'zh-CN', gr: 'de' }[activeLanguage]
        ?? activeLanguage
        ?? settingsKey.DEFAULT_LOCALE;

    // ─── CSS según dirección del idioma ──────────────────────────────────────
    useEffect(() => {
        if (updatedLanguage === "ar") {
            require("./assets/css/custom.rtl.css");
            require("./assets/css/style.rtl.css");
            require("./assets/css/frontend.rtl.css");
        } else {
            require("./assets/css/custom.css");
            require("./assets/css/style.css");
            // El POS se mantiene en SCSS fuente; cargar el CSS precompilado
            // antiguo dejaba fuera los estilos del rediseño EcuaPos.
            require("./assets/scss/frontend/frontend.scss");
        }

        // Las hojas CSS históricas incluyen estilos antiguos para avisos.
        // Cargamos el lenguaje visual actual al final para que Toastify,
        // SweetAlert y las alertas inline mantengan la misma apariencia.
        require("./assets/scss/custom/alerts-modern.scss");
    }, [location.pathname]);

    // ─── Soporte RTL ─────────────────────────────────────────────────────────
    useEffect(() => {
        addRTLSupport(updatedLanguage ?? selectedLanguage);
    }, [updatedLanguage, selectedLanguage]);

    // ─── Carga inicial de datos ───────────────────────────────────────────────
    const loadWorkspace = useCallback(() => dispatch(fetchMyStores()).then(() => {
        dispatch(fetchConfig());
        dispatch(fetchFrontSetting());
    }), [dispatch]);

    const accessModeRef = useRef(null);

    const checkSaaSAccess = useCallback(async () => {
        if (!token || isSuperAdmin) return { can_access: true };
        try {
            const response = await apiConfig.get('subscription-portal');
            const access = response.data.data;
            setSaasAccess(access);
            if (access.can_access) {
                localStorage.setItem(SAAS_OFFLINE_LEASE_KEY, JSON.stringify({
                    organization_id: access.organization?.id,
                    valid_until: access.offline_access_until,
                }));
                await loadWorkspace();
            } else {
                // Sin permiso offline: el modo consulta exige servidor, que
                // es quien decide qué se puede leer.
                localStorage.removeItem(SAAS_OFFLINE_LEASE_KEY);
                // El modo consulta necesita el mismo arranque que la app
                // normal (permisos, tiendas, ajustes). Son todos GET, que
                // EnsureActiveSubscription deja pasar con la suscripción
                // vencida; sin esto AdminApp se queda en el cargador.
                //
                // Solo al ENTRAR al modo: cada escritura rechazada dispara
                // saas:access-blocked, que revalida el acceso. Sin esta
                // guarda, un usuario probando botones recargaría el
                // workspace entero en cada intento.
                if (access.access_mode === 'read_only' && accessModeRef.current !== 'read_only') {
                    await loadWorkspace();
                }
            }
            accessModeRef.current = access.access_mode ?? (access.can_access ? 'full' : 'blocked');
            return access;
        } catch (error) {
            if (!error.response) {
                let lease = null;
                try { lease = JSON.parse(localStorage.getItem(SAAS_OFFLINE_LEASE_KEY) || 'null'); } catch (_) {}
                const selectedOrganizationId = Number(localStorage.getItem(Tokens.CURRENT_ORGANIZATION_ID) || 0);
                const leaseIsValid = lease?.valid_until
                    && new Date(lease.valid_until).getTime() > Date.now()
                    && selectedOrganizationId > 0
                    && Number(lease.organization_id) === selectedOrganizationId;
                if (leaseIsValid) {
                    const offlineAccess = { can_access: true, offline: true, offline_access_until: lease.valid_until };
                    setSaasAccess(offlineAccess);
                    await loadWorkspace();
                    return offlineAccess;
                }
                const offlineBlocked = { can_access: false, reason: 'OFFLINE_LEASE_EXPIRED', plans: [] };
                setSaasAccess(offlineBlocked);
                return offlineBlocked;
            }
            const blocked = { can_access: false, reason: 'ACCESS_UNAVAILABLE', plans: [] };
            setSaasAccess(blocked);
            return blocked;
        }
    }, [token, isSuperAdmin, loadWorkspace]);

    useEffect(() => { checkSaaSAccess(); }, [checkSaaSAccess]);

    useEffect(() => {
        // El interceptor no puede despachar al store (no se exporta desde
        // index.js), así que reenvía el 402 como evento y el aviso se arma
        // acá. Sin esto, en modo consulta el usuario aprieta "Guardar", no
        // pasa nada visible y parece que la app está rota.
        const handleBlockedAccess = event => {
            const message = event.detail?.message;
            if (message) {
                dispatch(addToast({ text: message, type: toastType.ERROR }));
            }
            checkSaaSAccess();
        };
        window.addEventListener('saas:access-blocked', handleBlockedAccess);
        return () => window.removeEventListener('saas:access-blocked', handleBlockedAccess);
    }, [checkSaaSAccess, dispatch]);

    // ─── Redirección según permisos ───────────────────────────────────────────
    const [redirectTo, setRedirectTo] = useState("/app/dashboard");

    useEffect(() => {
        if (!config?.length) return;
        setRedirectTo(getDefaultRouteForPermissions(config));
    }, [config]);

    const authenticatedHome = isSuperAdmin ? "/app/super-admin/dashboard" : redirectTo;

    return (
        <div className="d-flex flex-column flex-root">
            <IntlProvider
                locale={intlLocale}
                messages={messages}
            >
                <Routes>
                    <Route path="/login" element={token ? <Navigate replace to={authenticatedHome} /> : <Login />} />
                    <Route path="/crear-cuenta" element={token ? <Navigate replace to={authenticatedHome} /> : <Onboarding />} />
                    <Route 
                        path="reset-password/:token/:email"
                        element={<ResetPassword />}
                    />
                    <Route
                        path="forgot-password"
                        element={<ForgotPassword />}
                    />
                    <Route
                        path="app/super-admin/*"
                        element={token ? <SuperAdminApp /> : <Navigate replace to="/login" />}
                    />
                    <Route
                        path="app/*"
                        element={!token
                            ? <Navigate replace to="/login" />
                            : saasAccess === null
                                ? <TopProgressBar isLoading />
                                : saasAccess.can_access
                                    ? (saasAccess.offline && !location.pathname.startsWith('/app/pos')
                                        ? <Navigate replace to="/app/pos" />
                                        : <AdminApp config={config} />)
                                    // Suscripción vencida con la organización
                                    // todavía activa: la app abre en modo
                                    // consulta. El muro de pago queda para el
                                    // bloqueo real (organización desactivada,
                                    // o acceso que no se pudo verificar).
                                    : saasAccess.access_mode === 'read_only'
                                        ? <>
                                            <AdminApp config={config} />
                                            <ReadOnlyBanner
                                                canManage={saasAccess.can_manage}
                                                organizationName={saasAccess.organization?.name}
                                            />
                                        </>
                                        : <SubscriptionAccessGate access={saasAccess} onRefresh={checkSaaSAccess} />}
                    />
                    <Route
                        path="/"
                        element={
                            <Navigate
                                replace
                                to={token ? authenticatedHome : "/login"}
                            />
                        }
                    />
                    <Route path="*" element={<Navigate replace to={"/"} />} />
                </Routes>
                <Toasts
                    language={updatedLanguage ?? selectedLanguage}
                />
            </IntlProvider>
        </div>
    );
}

export default App;
