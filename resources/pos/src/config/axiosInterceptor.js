import {Tokens, errorMessage} from '../constants';
import {environment} from './environment'

export default {
    // El parámetro `isToken` (siempre true en los 3 call sites reales:
    // apiConfig.js, apiConfigWithoutToken.js, apiConfigWthFormData.js)
    // quedaba shadowed por un `let isToken` local para el resto de la
    // función -- por Temporal Dead Zone, el `if (isToken) return config`
    // de abajo referenciaba esa variable local (no el parámetro), lo cual
    // revienta con ReferenceError en JS estándar. Hoy "funciona" solo
    // porque Babel transpila `let`->`var` (que se inicializa en
    // `undefined`, no en TDZ), así que ese `if` nunca era cierto y el
    // token SIEMPRE se intentaba adjuntar -- que es el comportamiento
    // real que espera toda la app. Se elimina la rama muerta y se
    // renombra la variable local para no depender de ese detalle de
    // transpilación.
    setupInterceptors: (axios, isToken = false, isFormData = false) => {
        axios.interceptors.request.use((config) => {
                const token = localStorage.getItem(Tokens.ADMIN);
                if (token) {
                    config.headers['Authorization'] = `Bearer ${token}`;
                } else {
                    if (!window.location.href.includes('login') && !window.location.href.includes('reset-password') && !window.location.href.includes('forgot-password') && !window.location.href.includes('crear-cuenta')) {
                        window.location.href = environment.URL + '/sistema#/' + 'login';
                    }
                }
                // Validado siempre server-side contra user_store antes de
                // usarse (ver ResolveActiveStore::handle()) -- acá solo se
                // adjunta, nunca se confía en él para nada del lado cliente.
                const storeId = localStorage.getItem(Tokens.CURRENT_STORE_ID);
                if (storeId && !config.headers['X-Store-Id']) {
                    config.headers['X-Store-Id'] = storeId;
                }
                const organizationId = localStorage.getItem(Tokens.CURRENT_ORGANIZATION_ID);
                if (organizationId && !config.headers['X-Organization-Id']) {
                    config.headers['X-Organization-Id'] = organizationId;
                }
                if (isFormData) {
                    config.headers['Content-Type'] = 'multipart/form-data';
                }
                return config;
            },
            (error) => {
                return Promise.reject(error);
            }
        );
        axios.interceptors.response.use(
            response => successHandler(response),
            error => errorHandler(error)
        );
        const errorHandler = (error) => {
            // Cuando no hay internet Axios no recibe una respuesta HTTP.
            // Antes se intentaba leer error.response.status igualmente y
            // el POS terminaba lanzando otro TypeError, ocultando la causa
            // real e impidiendo que el catálogo local pudiera recuperarse.
            if (!error.response) {
                return Promise.reject(error);
            }

            if (error.response.status === 401
                || error.response.data.message === errorMessage.TOKEN_NOT_PROVIDED
                || error.response.data.message === errorMessage.TOKEN_INVALID
                || error.response.data.message === errorMessage.TOKEN_INVALID_SIGNATURE
                || error.response.data.message === errorMessage.TOKEN_EXPIRED) {
                localStorage.removeItem(Tokens.ADMIN);
                localStorage.removeItem(Tokens.USER);
                localStorage.removeItem(Tokens.GET_PERMISSIONS);
                window.location.href = environment.URL + '/sistema#' + '/login';
                return Promise.reject({...error});
            }else if(error.response.status === 402
                && ['trial_expired', 'subscription_inactive', 'organization_inactive', 'subscription_missing']
                    .includes(error.response.data?.restriction)) {
                window.dispatchEvent(new CustomEvent('saas:access-blocked', {
                    detail: error.response.data,
                }));
                return Promise.reject({...error});
            }else if(error.response.status === 403 || error.response.status === 404) {
                // Sin el reject, esta rama devolvía `undefined` (la
                // ausencia de return se interpreta como RESOLUCIÓN, no
                // rechazo, del interceptor) -- cualquier .then(response
                // => response.data) en el componente que llamó recibía
                // response=undefined y reventaba con "Cannot read
                // properties of undefined (reading 'data')" en vez de
                // simplemente no ejecutarse. Eso, sumado al redirect de
                // abajo, producía un loop de recargas cuando la propia
                // pantalla a la que redirige también disparaba el mismo
                // 403 (ver SellerDashboard.js llamando /api/sales sin
                // el permiso manage_sale).
                // Algunas pantallas manejan el 403/404 de forma local
                // (por ejemplo, el visor privado de comprobantes). En esos
                // casos navegar al dashboard desmontaba el modal y dejaba
                // una pantalla en blanco. El request puede pedir conservar
                // la ubicación y mostrar su propio mensaje de error.
                if (!error.config?.skipGlobalErrorRedirect) {
                    window.location.href = environment.URL + '/sistema#' + '/app/dashboard';
                }
                return Promise.reject({...error});
            }else {
                return Promise.reject({...error})
            }
        };
        const successHandler = (response) => {
            return response;
        };
    }
};
