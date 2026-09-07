import apiConfig from '../../config/apiConfig';
import {setLoading} from './loadingAction';
import {downloadAuthenticatedFile} from '../../shared/downloadAuthenticatedFile';

export const totalStockReportExcel = (warehouse, filter = {}, isLoading = true, setIsWarehouseValue) => async (dispatch) => {
    if (isLoading) {
        dispatch(setLoading(true))
    }
    await apiConfig.get(`stock-report-excel?warehouse_id=${warehouse}`)
        .then(async (response) => {
            await downloadAuthenticatedFile(response.data.data.stock_report_excel_url, {filename: 'reporte-stock.xlsx'});
            setIsWarehouseValue(false);
            if (isLoading) {
                dispatch(setLoading(false))
            }
        })
        .catch(() => {
        });
};
