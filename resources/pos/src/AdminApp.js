import React, { Suspense } from "react";
import { Route, Navigate, Routes } from "react-router-dom";
import "../../pos/src/assets/sass/style.react.scss";
import { Tokens } from "./constants";
import { hasAnyPermission, ProtectedRoute } from "./shared/sharedMethod";
import { route } from "./routes";
import TopProgressBar from "./shared/components/loaders/TopProgressBar";
import { useSelector } from "react-redux";

function AdminApp(props) {
    const { config } = props;
    const token = localStorage.getItem(Tokens.ADMIN);
    const { allConfigData } = useSelector((state) => state);
    const isReadOnly = allConfigData?.can_write === false;

    const isMutationRoute = (path) => {
        if (path === "profile/edit" || path === "subscription") return false;

        return /(^|\/)(create|edit)(\/|$)/i.test(path)
            || [
                "sri-config",
                "settings",
                "prefixes",
                "mail-settings",
                "catalog-settings",
                "sms-api",
            ].includes(path)
            || path === "sales/return/:id"
            || path === "quotations/Create_sale/:id"
            || path === "email-templates/:id"
            || path === "sms-templates/:id"
            || path === "languages/:id";
    };

    const prepareRoutes = (config) => {
        const permissions = config;
        let filterRoutes = [];
        route.forEach((route) => {
            if (
                hasAnyPermission(permissions, route.permission) ||
                route.permission === ""
            ) {
                filterRoutes.push(route);
            }
        });
        return filterRoutes;
    };

    if (config.length === 0 && token !== null) {
        return <TopProgressBar />;
    }

    const routes = config && prepareRoutes(config);

    return (
        <Suspense fallback={<TopProgressBar />}>
            <Routes>
                {routes.map((route, index) => {
                    return route.ele ? (
                        <Route
                            key={index}
                            exact={true}
                            path={route.path}
                            element={
                                token !== null && isReadOnly && isMutationRoute(route.path) ? (
                                    <Navigate replace to={"/app/dashboard"} />
                                ) : token !== null ? (
                                    <ProtectedRoute
                                        allConfigData={allConfigData}
                                        route={route.path}
                                    >
                                        {route.ele}
                                    </ProtectedRoute>
                                ) : (
                                    <Navigate replace to={"/login"} />
                                )
                            }
                        />
                    ) : null;
                })}
                <Route path="*" element={<Navigate replace to={"/"} />} />
            </Routes>
        </Suspense>
    );
}

export default AdminApp;
