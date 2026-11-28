import React from 'react';
import CustomerDropDown from "../pos-dropdown/CustomerDropdown";
import WarehouseDropDown from "../pos-dropdown/WarehouseDropDown";
import { Row } from "react-bootstrap-v5";

const PosHeader = ( props ) => {
    const { setSelectedCustomerOption, selectedCustomerOption, setSelectedOption, selectedOption, customerModel, offlineMode } = props;

    return (
        <div className='top-nav my-3'>
            <CustomerDropDown
                setSelectedCustomerOption={setSelectedCustomerOption}
                selectedCustomerOption={selectedCustomerOption}
                customerModel={customerModel}
                offlineMode={offlineMode}
                warehouseSlot={
                    <Row className="align-items-center justify-content-between grp-select h-100">
                        <WarehouseDropDown setSelectedOption={setSelectedOption}
                            selectedOption={selectedOption} />
                    </Row>
                }
            />
        </div>
    )
};
export default PosHeader
