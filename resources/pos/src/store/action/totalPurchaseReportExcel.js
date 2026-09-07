import apiConfig from '../../config/apiConfig';
import {setLoading} from './loadingAction';
import {downloadAuthenticatedFile} from '../../shared/downloadAuthenticatedFile';

export const totalPurchaseReportExcel = (dates, filter = {}, isLoading = true, setIsWarehouseValue) => async (dispatch) => {
    if (isLoading) {
        dispatch(setLoading(true))
    }
    await apiConfig.get(`total-purchase-report-excel?start_date=${dates.start_date ? dates.start_date : null }&end_date=${dates.end_date ? dates.end_date : null}`)
        .then(async (response) => {
            await downloadAuthenticatedFile(response.data.data.total_purchase_excel_url, {filename: 'reporte-compras.xlsx'});
            setIsWarehouseValue(false);
            if (isLoading) {
                dispatch(setLoading(false))
            }
        })
        .catch(() => {
        });
};
