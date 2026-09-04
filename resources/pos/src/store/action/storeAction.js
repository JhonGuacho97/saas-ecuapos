import { apiBaseURL, storeActionType, Tokens, toastType } from '../../constants';
import apiConfig from '../../config/apiConfig';
import { addToast } from './toastAction';
import {
    isNetworkError,
    loadCachedResource,
    saveOfflineSnapshot,
} from '../../offline/catalogStorage';

/**
 * Trae las tiendas a las que el usuario autenticado tiene acceso (ver
 * StoreAPIController::misTiendas(), que incluye también las
 * desactivadas -- el selector del Header las muestra bloqueadas) y
 * resuelve cuál queda "activa", en este orden:
 *
 * 1. La guardada en localStorage, si sigue estando en la lista Y
 *    activa (el usuario la eligió antes -- máxima prioridad, incluso
 *    por sobre la predeterminada). Si se desactivó mientras tanto, se
 *    descarta y se re-evalúa como si no hubiera nada guardado.
 * 2. La marcada is_default en el catálogo de tiendas (ver pantalla
 *    Tienda > radio "Predeterminada"), si está activa -- así el
 *    primer login (o cualquiera sin preferencia guardada) arranca en
 *    algo elegido a propósito, no en la primera que devuelva la BD.
 * 3. Si hay EXACTAMENTE una tienda ACTIVA, se auto-selecciona sin
 *    pedirle nada al usuario -- mismo criterio que ya usa el
 *    middleware ResolveActiveStore en el backend cuando no llega el
 *    header X-Store-Id.
 * 4. Ninguna de las anteriores: queda sin resolver, el selector del
 *    Header se encarga de pedírsela al usuario.
 */
export const fetchMyStores = () => async (dispatch) => {
    const applyStores = (stores) => {
        dispatch({ type: storeActionType.FETCH_MY_STORES, payload: stores });

        const savedId = localStorage.getItem(Tokens.CURRENT_STORE_ID);
        const savedStore = savedId && stores.find((store) => String(store.id) === savedId);

        if (savedStore && savedStore.is_active) {
            dispatch({ type: storeActionType.SET_CURRENT_STORE_ID, payload: savedId });
            persistOrganization(dispatch, savedStore.organization_id);
            return;
        }

        const activeStores = stores.filter((store) => store.is_active);
        const defaultStore = activeStores.find((store) => store.is_default);

        if (defaultStore) {
            dispatch(setCurrentStore(defaultStore.id));
        } else if (activeStores.length === 1) {
            dispatch(setCurrentStore(activeStores[0].id));
        } else if (navigator.onLine) {
            localStorage.removeItem(Tokens.CURRENT_STORE_ID);
            localStorage.removeItem(Tokens.CURRENT_ORGANIZATION_ID);
            dispatch({ type: storeActionType.SET_CURRENT_ORGANIZATION_ID, payload: null });
        }
    };

    const useCachedStores = async () => {
        const snapshot = await loadCachedResource('my-stores', { scope: 'user' });
        if (snapshot) applyStores(snapshot.payload || []);
        return snapshot;
    };

    if (!navigator.onLine) return useCachedStores();

    try {
        const response = await apiConfig.get(apiBaseURL.MY_STORES);
        const stores = response.data.data || [];
        applyStores(stores);
        await saveOfflineSnapshot('my-stores', stores, { scope: 'user' }).catch(() => null);
        return stores;
    } catch (error) {
        if (isNetworkError(error)) return useCachedStores();
        dispatch(addToast({
            text: error?.response?.data?.message || 'No se pudieron cargar las tiendas.',
            type: toastType.ERROR,
        }));
        return null;
    }
};

/**
 * Cambiar de tienda activa recarga toda la app a propósito -- es la
 * forma más simple y segura de garantizar que ningún dato de la
 * tienda anterior (listados en Redux, formularios abiertos, etc.)
 * sobreviva al cambio de contexto.
 */
export const setCurrentStore = (storeId) => (dispatch, getState) => {
    localStorage.setItem(Tokens.CURRENT_STORE_ID, String(storeId));
    dispatch({ type: storeActionType.SET_CURRENT_STORE_ID, payload: String(storeId) });
    const selectedStore = getState().myStores.stores.find((store) => String(store.id) === String(storeId));
    persistOrganization(dispatch, selectedStore?.organization_id);
};

const persistOrganization = (dispatch, organizationId) => {
    if (organizationId === null || organizationId === undefined || organizationId === '') {
        localStorage.removeItem(Tokens.CURRENT_ORGANIZATION_ID);
        dispatch({ type: storeActionType.SET_CURRENT_ORGANIZATION_ID, payload: null });
        return;
    }

    localStorage.setItem(Tokens.CURRENT_ORGANIZATION_ID, String(organizationId));
    dispatch({
        type: storeActionType.SET_CURRENT_ORGANIZATION_ID,
        payload: String(organizationId),
    });
};
