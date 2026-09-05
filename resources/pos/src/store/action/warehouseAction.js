import apiConfig from "../../config/apiConfig";
import { apiBaseURL, warehouseActionType, toastType } from "../../constants";
import requestParam from "../../shared/requestParam";
import { addToast } from "./toastAction";
import {
    setTotalRecord,
    addInToTotalRecord,
    removeFromTotalRecord,
} from "./totalRecordAction";
import { setLoading } from "./loadingAction";
import { getFormattedMessage } from "../../shared/sharedMethod";
import { setSavingButton } from "./saveButtonAction";
import {
    isNetworkError,
    loadCachedResource,
    saveOfflineSnapshot,
} from "../../offline/catalogStorage";

export const fetchWarehouses =
    (filter = {}, isLoading = true, includeInactive = false) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        let url = apiBaseURL.WAREHOUSES;
        if (
            !_.isEmpty(filter) &&
            (filter.page ||
                filter.pageSize ||
                filter.search ||
                filter.order_By ||
                filter.created_at)
        ) {
            url += requestParam(filter, null, null, null, url);
        }
        if (includeInactive) {
            url += (url.includes("?") ? "&" : "?") + "include_inactive=1";
        }
        apiConfig
            .get(url)
            .then((response) => {
                dispatch({
                    type: warehouseActionType.FETCH_WAREHOUSES,
                    payload: response.data.data,
                });
                dispatch(
                    setTotalRecord(
                        response.data.meta.total !== undefined &&
                            response.data.meta.total >= 0
                            ? response.data.meta.total
                            : response.data.data.total
                    )
                );
                if (isLoading) {
                    dispatch(setLoading(false));
                }
            })
            .catch(({ response }) => {
                dispatch(
                    addToast({
                        text: response.data.message,
                        type: toastType.ERROR,
                    })
                );
            });
    };

export const fetchWarehouse =
    (warehouseId, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        apiConfig
            .get(apiBaseURL.WAREHOUSES + "/" + warehouseId)
            .then((response) => {
                dispatch({
                    type: warehouseActionType.FETCH_WAREHOUSE,
                    payload: response.data.data,
                });
                if (isLoading) {
                    dispatch(setLoading(false));
                }
            })
            .catch(({ response }) => {
                dispatch(
                    addToast({
                        text: response.data.message,
                        type: toastType.ERROR,
                    })
                );
            });
    };

export const addWarehouse = (warehouse, navigate) => async (dispatch) => {
    dispatch(setSavingButton(true));
    await apiConfig
        .post(apiBaseURL.WAREHOUSES, warehouse)
        .then((response) => {
            dispatch({
                type: warehouseActionType.ADD_WAREHOUSE,
                payload: response.data.data,
            });
            dispatch(
                addToast({
                    text: getFormattedMessage(
                        "warehouse.success.create.message"
                    ),
                })
            );
            navigate("/app/warehouse");
            dispatch(addInToTotalRecord(1));
            dispatch(setSavingButton(false));
        })
        .catch(({ response }) => {
            dispatch(setSavingButton(false));
            dispatch(
                addToast({ text: response.data.message, type: toastType.ERROR })
            );
        });
};

export const editWarehouse =
    (warehouseId, warehouse, navigate) => async (dispatch) => {
        dispatch(setSavingButton(true));
        apiConfig
            .patch(apiBaseURL.WAREHOUSES + "/" + warehouseId, warehouse)
            .then((response) => {
                dispatch({
                    type: warehouseActionType.EDIT_WAREHOUSE,
                    payload: response.data.data,
                });
                dispatch(
                    addToast({
                        text: getFormattedMessage(
                            "warehouse.success.edit.message"
                        ),
                    })
                );
                navigate("/app/warehouse");
                dispatch(setSavingButton(false));
            })
            .catch(({ response }) => {
                dispatch(setSavingButton(false));
                dispatch(
                    addToast({
                        text: response.data.message,
                        type: toastType.ERROR,
                    })
                );
            });
    };

export const toggleWarehouseStatus = (warehouse) => async (dispatch) => {
    try {
        const response = await apiConfig.patch(
            apiBaseURL.WAREHOUSES + "/" + warehouse.id,
            {
                name: warehouse.name,
                email: warehouse.email,
                phone: warehouse.phone,
                country: warehouse.country,
                city: warehouse.city,
                zip_code: warehouse.zip_code,
                is_active: !warehouse.is_active,
            }
        );

        dispatch({
            type: warehouseActionType.EDIT_WAREHOUSE,
            payload: response.data.data,
        });
        dispatch(
            addToast({
                text: warehouse.is_active
                    ? "Bodega desactivada correctamente."
                    : "Bodega activada correctamente.",
            })
        );

        return true;
    } catch (error) {
        dispatch(
            addToast({
                text:
                    error?.response?.data?.message ||
                    "No se pudo actualizar el estado de la bodega.",
                type: toastType.ERROR,
            })
        );

        return false;
    }
};

export const deleteWarehouse = (warehouseId) => async (dispatch) => {
    apiConfig
        .delete(apiBaseURL.WAREHOUSES + "/" + warehouseId)
        .then((response) => {
            dispatch(removeFromTotalRecord(1));
            dispatch({
                type: warehouseActionType.DELETE_WAREHOUSE,
                payload: warehouseId,
            });
            dispatch(
                addToast({
                    text: getFormattedMessage(
                        "warehouse.success.delete.message"
                    ),
                })
            );
        })
        .catch(({ response }) => {
            dispatch(
                addToast({ text: response.data.message, type: toastType.ERROR })
            );
        });
};

export const fetchAllWarehouses = (forPos = false) => async (dispatch) => {
    const useCachedWarehouses = async () => {
        const snapshot = await loadCachedResource("warehouses");
        if (snapshot) {
            dispatch({
                type: warehouseActionType.FETCH_ALL_WAREHOUSES,
                payload: snapshot.payload,
            });
        }
        return snapshot;
    };

    if (!navigator.onLine) return useCachedWarehouses();

    try {
        const response = await apiConfig.get("warehouses?page[size]=0" + (forPos ? "&for_pos=1" : ""));
        const warehouses = response.data.data || [];
        dispatch({
            type: warehouseActionType.FETCH_ALL_WAREHOUSES,
            payload: warehouses,
        });
        await saveOfflineSnapshot("warehouses", warehouses).catch(() => null);
        return warehouses;
    } catch (error) {
        if (isNetworkError(error)) return useCachedWarehouses();
        dispatch(addToast({
            text: error?.response?.data?.message || "No se pudieron cargar las bodegas.",
            type: toastType.ERROR,
        }));
        return null;
    }
};

export const fetchWarehouseDetails =
    (WarehouseId, isLoading = true) =>
    async (dispatch) => {
        if (isLoading) {
            dispatch(setLoading(true));
        }
        apiConfig
            .get(apiBaseURL.WAREHOUSE_DETAILS + "/" + WarehouseId)
            .then((response) => {
                dispatch({
                    type: warehouseActionType.FETCH_WAREHOUSE_DETAILS,
                    payload: response.data.data,
                });
                if (isLoading) {
                    dispatch(setLoading(false));
                }
            })
            .catch(({ response }) => {
                dispatch(
                    addToast({
                        text: response.data.message,
                        type: toastType.ERROR,
                    })
                );
            });
    };
