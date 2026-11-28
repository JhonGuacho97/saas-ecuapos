import apiConfig from '../../../config/apiConfig';
import { apiBaseURL, toastType } from '../../../constants';
import { addToast } from './../toastAction'
import { getFormattedMessage } from '../../../shared/sharedMethod';
import { setSavingButton } from "./../saveButtonAction";
import { fetchAllCustomer } from "../customerAction";
import { enqueueOfflineCustomer, offlineCustomerToResource } from '../../../offline/catalogStorage';
import { requestOfflineSaleBackgroundSync } from '../../../offline/backgroundSync';

export const addCustomer = ( supplier, hide, options = {} ) => async ( dispatch ) => {
    dispatch( setSavingButton( true ) )
    if (options.offlineMode || !navigator.onLine) {
        try {
            const customer = await enqueueOfflineCustomer(supplier);
            await requestOfflineSaleBackgroundSync().catch(() => null);
            const resource = offlineCustomerToResource(customer);
            await dispatch(fetchAllCustomer());
            dispatch(addToast({ text: 'Cliente guardado en el dispositivo. Se sincronizará al recuperar conexión.' }));
            dispatch(setSavingButton(false));
            hide(false);
            return resource;
        } catch (error) {
            dispatch(setSavingButton(false));
            dispatch(addToast({ text: error?.message || 'No se pudo guardar el cliente offline.', type: toastType.ERROR }));
            return null;
        }
    }

    return apiConfig.post( apiBaseURL.CUSTOMERS, supplier )
        .then( async ( response ) => {
            await dispatch( fetchAllCustomer() )
            dispatch( addToast( { text: getFormattedMessage( 'customer.success.create.message' ) } ) );
            dispatch( setSavingButton( false ) )
            hide( false )
            return response.data.data;
        } )
        .catch( ( error ) => {
            dispatch( setSavingButton( false ) )
            dispatch( addToast(
                { text: error?.response?.data?.message || 'No se pudo crear el cliente.', type: toastType.ERROR } ) );
            return null;
        } );
};

// Edición desde el POS: guarda y deja al cajero en la venta (la edición del
// panel de Clientes redirige a la lista, acá no sirve).
export const updatePosCustomer = ( customerId, values, hide ) => async ( dispatch ) => {
    dispatch( setSavingButton( true ) );
    const data = {
        name: values.name,
        email: values.email,
        phone: values.phone,
        country: values.country,
        city: values.city,
        address: values.address,
        identification: values.identification,
        tipo_identificacion: values.tipo_identificacion,
        credit_enabled: Boolean( values.credit_enabled ),
        credit_limit: values.credit_enabled ? values.credit_limit : 0,
        default_payment_terms_days: values.credit_enabled ? values.default_payment_terms_days : 0,
    };
    try {
        const response = await apiConfig.patch( `${ apiBaseURL.CUSTOMERS }/${ customerId }`, data );
        await dispatch( fetchAllCustomer() );
        dispatch( addToast( { text: getFormattedMessage( 'customer.success.edit.message' ) } ) );
        dispatch( setSavingButton( false ) );
        hide?.( false );
        return response.data.data;
    } catch ( error ) {
        dispatch( setSavingButton( false ) );
        dispatch( addToast( {
            text: error?.response?.data?.message || 'No se pudo actualizar el cliente.',
            type: toastType.ERROR,
        } ) );
        return null;
    }
};
