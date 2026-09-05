import apiConfig from '../../config/apiConfig';
import { authActionType, Tokens, toastType, apiBaseURL } from '../../constants';
import { fetchPermissions } from './permissionAction';
import { addToast } from './toastAction';
import { fetchFrontSetting } from './frontSettingAction';
import { setLanguage } from './changeLanguageAction';
import { getFormattedMessage } from '../../shared/sharedMethod';
import { fetchConfig } from "./configAction";
import { fetchMyStores } from "./storeAction";
import { revokeOfflineSyncCredential } from "../../offline/backgroundSync";

const mapPermissionToRoute = (permission) => {
    const entity = permission.split('_')[1];
    return `/app/${entity}`;
};

export const loginAction = (user, navigate, setLoading) => async (dispatch) => {
    const previousLanguage = localStorage.getItem(Tokens.UPDATED_LANGUAGE);
    await apiConfig.post('login', user)
        .then(async (response) => {
            // La tienda activa de la sesión ANTERIOR (otro usuario, en el
            // mismo navegador) quedaba pegada en localStorage -- el
            // interceptor la sigue mandando como X-Store-Id en las
            // primeras peticiones de este login nuevo, antes de que
            // fetchMyStores() (más abajo) tenga chance de corregirla. Si
            // el usuario que acaba de entrar no tiene acceso a esa
            // tienda vieja, ResolveActiveStore la rechaza -- "no tienes
            // permisos para esa tienda" en el primer login tras crear un
            // usuario para otra tienda distinta a la que estaba activa.
            localStorage.removeItem(Tokens.CURRENT_STORE_ID);
            localStorage.removeItem(Tokens.CURRENT_ORGANIZATION_ID);

            localStorage.setItem(Tokens.ADMIN, response.data.data.token);
            localStorage.setItem(Tokens.GET_PERMISSIONS, response.data.data.permissions);
            localStorage.setItem(Tokens.USER, response.data.data.user.email);
            localStorage.setItem(Tokens.IMAGE, response.data.data.user.image_url);
            localStorage.setItem(Tokens.FIRST_NAME, response.data.data.user.first_name);
            localStorage.setItem(Tokens.LANGUAGE, response.data.data.user.language);
            localStorage.setItem(Tokens.LAST_NAME, response.data.data.user.last_name);
            if (response.data.data.role_name) {
                localStorage.setItem(Tokens.ROLE_NAME, response.data.data.role_name);
            } else {
                localStorage.removeItem(Tokens.ROLE_NAME);
            }
            localStorage.setItem('loginUserArray', JSON.stringify(response.data.data.user));
            localStorage.setItem(Tokens.IS_SUPER_ADMIN, response.data.data.is_super_admin ? 'true' : 'false');
            dispatch({ type: authActionType.LOGIN_USER, payload: response.data.data });
            dispatch(setLanguage(response.data.data.user.language));
            localStorage.setItem(Tokens.UPDATED_LANGUAGE, response.data.data.user.language);

            const userPermissions = response.data.data.permissions;
            const mappedRoutes = userPermissions.map(mapPermissionToRoute);

            if (response.data.data.is_super_admin) {
                navigate('/app/super-admin/dashboard');
                dispatch(addToast({ text: getFormattedMessage('login.success.message') }));
                setLoading(false);
                return;
            }

            // Resolver el contexto antes de montar pantallas que consultan datos.
            await dispatch(fetchMyStores());
            await Promise.all([
                dispatch(fetchPermissions()),
                dispatch(fetchFrontSetting()),
                dispatch(fetchConfig()),
            ]);

            if (mappedRoutes && mappedRoutes.length > 0) {
                if (userPermissions.includes('manage_dashboard')) {
                    navigate('/app/dashboard');
                } else {
                    navigate(mappedRoutes[0]);
                }
            } else {
                navigate('/app/dashboard');
            }

            dispatch(addToast({ text: getFormattedMessage('login.success.message') }));

            if (response.data.data.user.language && response.data.data.user.language !== previousLanguage) {
                window.location.reload();
            }
        })
        .catch((error) => {
            const message = error?.response?.data?.message || 'Something went wrong';
            dispatch(addToast({ text: message, type: toastType.ERROR }));
            setLoading(false);
        });
};
export const logoutAction = (token, navigate) => async (dispatch) => {
    await revokeOfflineSyncCredential().catch(() => null);
    await apiConfig.post('logout', token)
        .then(() => {
            localStorage.removeItem(Tokens.ADMIN);
            localStorage.removeItem(Tokens.USER);
            localStorage.removeItem(Tokens.IMAGE);
            localStorage.removeItem(Tokens.FIRST_NAME);
            localStorage.removeItem(Tokens.LAST_NAME);
            localStorage.removeItem('loginUserArray');
            localStorage.removeItem(Tokens.UPDATED_EMAIL);
            localStorage.removeItem(Tokens.UPDATED_FIRST_NAME);
            localStorage.removeItem(Tokens.UPDATED_LAST_NAME);
            localStorage.removeItem(Tokens.USER_IMAGE_URL);
            localStorage.removeItem(Tokens.CURRENT_STORE_ID);
            localStorage.removeItem(Tokens.CURRENT_ORGANIZATION_ID);
            localStorage.removeItem(Tokens.ROLE_NAME);
            localStorage.removeItem(Tokens.IS_SUPER_ADMIN);
            navigate('/login');
            dispatch(addToast({ text: getFormattedMessage('logout.success.message') }));
        })
        .catch(({ response }) => {
            dispatch(addToast({ text: response.data.message, type: toastType.ERROR }));
        });
};

export const forgotPassword = (user) => async (dispatch) => {
    await apiConfig.post(apiBaseURL.ADMIN_FORGOT_PASSWORD, user).then((response) => {
        dispatch({ type: authActionType.ADMIN_FORGOT_PASSWORD, payload: response.data.message });
        dispatch(addToast({ text: getFormattedMessage('forgot-password-form.success.reset-link.label') }));
    }).catch(({ response }) => {
        dispatch({ type: toastType.ERROR, payload: response.data.message });
        dispatch(
            addToast({ text: response.data.message, type: toastType.ERROR }));
    });
};

export const resetPassword = (user, navigate) => async (dispatch) => {
    await apiConfig.post(apiBaseURL.ADMIN_RESET_PASSWORD, user).then((response) => {
        dispatch({ type: authActionType.ADMIN_RESET_PASSWORD, payload: user });
        dispatch(addToast(
            { text: getFormattedMessage('reset-password.success.update.message') }));
        navigate('/login');
    }).catch(({ response }) => {
        dispatch(
            addToast({ text: response.data.message, type: toastType.ERROR }))
    });
};

