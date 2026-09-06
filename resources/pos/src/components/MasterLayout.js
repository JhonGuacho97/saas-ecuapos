import React, { useEffect, useState } from "react";
import { connect } from "react-redux";
import AsideDefault from "./sidebar/asideDefault";
import Header from "./header/Header";
import Footer from "./footer/Footer";
import AsideTopSubMenuItem from "./sidebar/asideTopSubMenuItem";
import GlobalSearch from "./header/GlobalSearch";
import { Tokens } from "../constants";
import asideConfig from "../config/asideConfig";
import { environment } from "../config/environment";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faBars } from "@fortawesome/free-solid-svg-icons";
import { hasAnyPermission } from "../shared/sharedMethod";

const MasterLayout = (props) => {
    const {
        children,
        newPermissions,
        frontSetting,
        config,
        allConfigData,
    } = props;
    const [isResponsiveMenu, setIsResponsiveMenu] = useState(false);
    const [isMenuCollapse, setIsMenuCollapse] = useState(false);
    const newRoutes = config && prepareRoutes(config);
    const token = localStorage.getItem(Tokens.ADMIN);

    useEffect(() => {
        if (!token) {
            window.location.href = environment.URL + "/sistema#" + "/login";
        }
    }, []);

    const menuClick = () => {
        setIsResponsiveMenu(!isResponsiveMenu);
    };

    const menuIconClick = () => {
        setIsMenuCollapse(!isMenuCollapse);
    };

    return (
        <div className="d-flex flex-row flex-column-fluid">
            <AsideDefault
                asideConfig={newRoutes}
                frontSetting={frontSetting}
                isResponsiveMenu={isResponsiveMenu}
                menuClick={menuClick}
                menuIconClick={menuIconClick}
                isMenuCollapse={isMenuCollapse}
            />
            <div
                className={`${
                    isMenuCollapse === true ? "wrapper-res" : "wrapper"
                } d-flex flex-column flex-row-fluid`}
            >
                <div className="d-flex align-items-stretch justify-content-between header">
                    <div className="container-fluid d-flex align-items-stretch justify-content-xxl-between flex-grow-1">
                        <button
                            type="button"
                            className="btn d-flex align-items-center d-xl-none px-0"
                            title="Show aside menu"
                            onClick={menuClick}
                        >
                            <FontAwesomeIcon icon={faBars} className="fs-1" />
                        </button>
                        <AsideTopSubMenuItem
                            asideConfig={asideConfig}
                            isMenuCollapse={isMenuCollapse}
                        />
                        <GlobalSearch newRoutes={newRoutes} />
                        <Header newRoutes={newRoutes} />
                    </div>
                </div>
                <div className="content d-flex flex-column flex-column-fluid pt-7">
                    <div className="d-flex flex-column-fluid">
                        <div className="container-fluid">{children}</div>
                    </div>
                </div>
                <div className="container-fluid">
                    <Footer
                        allConfigData={allConfigData}
                        frontSetting={frontSetting}
                    />
                </div>
            </div>
        </div>
    );
};

const getRouteWithSubMenu = (route, permissions) => {
    const subRoutes = route.subMenu
        ? route.subMenu.filter(
              (item) =>
                  hasAnyPermission(permissions, item.permission) ||
                  item.permission === ""
          )
        : null;
    const newSubRoutes = subRoutes ? { ...route, newRoute: subRoutes } : route;
    return newSubRoutes;
};

const prepareRoutes = (config) => {
    const permissions = config;
    let filterRoutes = [];
    asideConfig.forEach((route) => {
        if (route.groupHeader) {
            // Pasa de largo -- se filtra abajo en pruneEmptyGroups() una vez
            // que ya sabemos qué rutas reales quedaron a cada lado.
            filterRoutes.push(route);
            return;
        }
        const permissionsRoute = getRouteWithSubMenu(route, permissions);
        if (
            hasAnyPermission(permissions, route.permission) ||
            route.permission === "" ||
            permissionsRoute.newRoute?.length
        ) {
            filterRoutes.push(permissionsRoute);
        }
    });
    return pruneEmptyGroups(filterRoutes);
};

// Un encabezado de grupo (GENERAL, VENTAS, ...) no tiene permission propio
// -- si ningún ítem real le sobrevivió al filtro de permisos justo
// después (porque el usuario no tiene acceso a nada de ese grupo, o
// porque el siguiente elemento es otro encabezado), se descarta para no
// mostrar un título de sección seguido de nada.
const pruneEmptyGroups = (routes) => {
    return routes.filter((route, index) => {
        if (!route.groupHeader) {
            return true;
        }
        const next = routes[index + 1];
        return Boolean(next) && !next.groupHeader;
    });
};

const mapStateToProps = (state) => {
    const newPermissions = [];
    const { permissions, settings, frontSetting, config, allConfigData } =
        state;

    if (permissions) {
        permissions.forEach((permission) =>
            newPermissions.push(permission.attributes.name)
        );
    }
    return { newPermissions, settings, frontSetting, config, allConfigData };
};

export default connect(mapStateToProps)(MasterLayout);
