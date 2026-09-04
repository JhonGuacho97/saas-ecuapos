import React, { useEffect, useState } from "react";
import { Route, useLocation, Navigate, Routes } from "react-router-dom";
import "../../pos/src/assets/sass/style.react.scss";
import { useDispatch, useSelector } from "react-redux";
import { IntlProvider } from "react-intl";
import { settingsKey, Tokens } from "./constants";
import Toasts from "./shared/toast/Toasts";
import { fetchFrontSetting } from "./store/action/frontSettingAction";
import { fetchConfig } from "./store/action/configAction";
import { fetchMyStores } from "./store/action/storeAction";
import { addRTLSupport, getDefaultRouteForPermissions } from "./shared/sharedMethod";
import Login from "./components/auth/Login";
import ResetPassword from "./components/auth/ResetPassword";
import ForgotPassword from "./components/auth/ForgotPassword";
import Onboarding from "./components/auth/Onboarding";
import AdminApp from "./AdminApp";
import useLanguage from "./hooks/useLanguage";

function App() {
    const dispatch = useDispatch();
    const location = useLocation();
    const token = localStorage.getItem(Tokens.ADMIN);
    const { config } = useSelector((state) => state);

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
    useEffect(() => {
        if (token) {
            dispatch(fetchConfig());
            dispatch(fetchFrontSetting());
            dispatch(fetchMyStores());
        }
    }, []);

    // ─── Redirección según permisos ───────────────────────────────────────────
    const [redirectTo, setRedirectTo] = useState("/app/dashboard");

    useEffect(() => {
        if (!config?.length) return;
        setRedirectTo(getDefaultRouteForPermissions(config));
    }, [config]);

    return (
        <div className="d-flex flex-column flex-root">
            <IntlProvider
                locale={intlLocale}
                messages={messages}
            >
                <Routes>
                    <Route path="/login" element={<Login />} />
                    <Route path="/crear-cuenta" element={<Onboarding />} />
                    <Route 
                        path="reset-password/:token/:email"
                        element={<ResetPassword />}
                    />
                    <Route
                        path="forgot-password"
                        element={<ForgotPassword />}
                    />
                    <Route
                        path="app/*"
                        element={<AdminApp config={config} />}
                    />
                    <Route
                        path="/"
                        element={
                            <Navigate
                                replace
                                to={token ? redirectTo : "/login"}
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
