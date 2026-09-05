import React, {useEffect, useState} from 'react';
import {InputGroup} from 'react-bootstrap-v5';
import Select from 'react-select';
import {connect} from 'react-redux';
import {fetchAllWarehouses} from '../../../store/action/warehouseAction';
import { getFormattedMessage } from '../../../shared/sharedMethod';

const WarehouseDropDown = (props) => {
    const {setSelectedOption, selectedOption, fetchAllWarehouses, allConfigData, settings, currentStoreId} = props;
    const [warehouses, setWarehouses] = useState([]);
    const [loaded, setLoaded] = useState(false);

    const warehouseOption = warehouses && warehouses.map((warehouse) => {
        return {value: warehouse.id, label: warehouse.attributes.name}
    });

    useEffect(() => {
        let cancelled = false;
        setLoaded(false);
        setWarehouses([]);
        setSelectedOption(null);
        if (!currentStoreId) return;
        fetchAllWarehouses(true).then((result) => {
            if (cancelled) return;
            const rows = Array.isArray(result) ? result : result?.payload || [];
            setWarehouses(rows.filter((row) => row.attributes?.is_active !== false));
            setLoaded(true);
        });
        return () => { cancelled = true; };
    }, [currentStoreId]);

    useEffect(() => {
        if (!loaded) return;
        const configMatches = String(allConfigData?.store_id) === String(currentStoreId);
        const settingsMatch = String(settings?.attributes?.store_id) === String(currentStoreId);
        if (navigator.onLine && !configMatches && !settingsMatch) return;
        // Una preferencia vieja nunca puede introducir una opción ajena.
        const selected = warehouseOption.find((option) => String(option.value) === String(selectedOption?.value));
        if (selected) return;
        const preferred = [configMatches && allConfigData?.default_warehouse_id, settingsMatch && settings?.attributes?.default_warehouse]
            .map((id) => warehouseOption.find((option) => String(option.value) === String(id)))
            .find(Boolean);
        const next = preferred || warehouseOption[0] || null;
        if (next || selectedOption) setSelectedOption(next);
    }, [loaded, warehouses, allConfigData, settings, selectedOption, currentStoreId]);

    const onChangeWarehouse = (obj) => {
        setSelectedOption(obj);
    };

    return (
        <div className='select-box col-6 ps-sm-2 position-relative'>
            <InputGroup>
                <InputGroup.Text id='basic-addon1' className='bg-transparent position-absolute border-0 z-index-1 input-group-text py-4 px-3'>
                    <i className="bi bi-house fs-3 text-gray-900" />
                </InputGroup.Text>
                <Select
                    placeholder='Seleccionar bodega'
                    isLoading={!loaded}
                    isDisabled={!loaded || warehouseOption.length === 0}
                    value={selectedOption}
                    onChange={onChangeWarehouse}
                    options={warehouseOption}
                    noOptionsMessage={() => getFormattedMessage('no-option.label')}
                />
            </InputGroup>
        </div>
    )
};

const mapStateToProps = (state) => {
    const {allConfigData, settings, myStores} = state;
    return {allConfigData, settings, currentStoreId: myStores.currentStoreId}
};
export default connect(mapStateToProps, {fetchAllWarehouses})(WarehouseDropDown);
