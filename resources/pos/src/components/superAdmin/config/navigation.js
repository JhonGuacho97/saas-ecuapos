import {
    faBuilding,
    faCalendarCheck,
    faChartLine,
    faCreditCard,
    faGear,
    faGlobe,
    faLayerGroup,
    faUsers,
} from '@fortawesome/free-solid-svg-icons';

export const SUPER_ADMIN_BASE_PATH = '/app/super-admin';

export const superAdminNavigation = [
    { key: 'dashboard', label: 'Resumen', icon: faChartLine },
    { key: 'organizations', label: 'Organizaciones', icon: faBuilding },
    { key: 'users', label: 'Usuarios', icon: faUsers },
    { key: 'plans', label: 'Planes', icon: faLayerGroup },
    { key: 'subscriptions', label: 'Suscripciones', icon: faCalendarCheck },
    { key: 'payments', label: 'Pagos', icon: faCreditCard },
    { key: 'landing', label: 'Landing page', icon: faGlobe },
    { key: 'settings', label: 'Configuración', icon: faGear },
];

export const getSuperAdminPageTitle = pathname =>
    superAdminNavigation.find(item => pathname.includes(`/${item.key}`))?.label || 'Resumen';
