// src/components/pos/CustomerForm.jsx

import React, { useEffect } from 'react';
import Modal from 'react-bootstrap/Modal';
import Form from 'react-bootstrap/Form';
import { connect } from 'react-redux';
import { editCustomer } from '../../../store/action/customerAction';
import { addCustomer } from '../../../store/action/pos/customerAction';
import ModelFooter from '../../../shared/components/modelFooter';
import { getFormattedMessage, placeholderText, numValidate } from '../../../shared/sharedMethod';
import IdentificacionField from '../../../components/customer/identificacionField';
import { useCustomerForm } from '../../../hooks/useCustomerFom';
import '../../../components/customer/customer-credit.scss';

const CustomerForm = (props) => {
    const { show, hide, singleCustomer, offlineMode, onCustomerCreated, initialValues, onEditSubmit } = props;

    const createCustomer = async (values) => {
        const customer = await props.addCustomer(values, hide, { offlineMode });
        if (!customer) return;
        onCustomerCreated?.({
            value: customer.id,
            label: customer.attributes?.name || values.name,
            offlineCustomerUuid: customer.attributes?.offline_client_uuid || null,
            offlineStatus: customer.attributes?.offline_status || null,
        });
    };

    const {
        customerData,
        customerValue,
        errors,
        sriLoading,
        isDisabled,
        onChangeInput,
        onSubmit,
        handleSriLookup,
    } = useCustomerForm({
        singleCustomer,
        addCustomerData: createCustomer,
        // En el POS la edición guarda sin salir de la venta (onEditSubmit).
        editCustomer: onEditSubmit || props.editCustomer,
        id: singleCustomer?.[0]?.id,
        initialValues,
    });

    // Si llega una cédula/RUC desde el buscador del POS, se consultan sus
    // datos en el SRI de una vez para no tener que escribirlos.
    useEffect(() => {
        const identification = initialValues?.identification || '';
        const tipo = initialValues?.tipo_identificacion;
        const complete = (tipo === '05' && identification.length === 10) || (tipo === '04' && identification.length === 13);
        if (complete && !offlineMode && !singleCustomer) handleSriLookup();
    }, []);

    return (
        <Modal show={show} onHide={() => hide(false)} keyboard={true} size="lg">
            <Modal.Header closeButton>
                <Modal.Title>
                    {getFormattedMessage(singleCustomer ? 'customer.edit.title' : 'customer.create.title')}
                </Modal.Title>
            </Modal.Header>
            <Modal.Body className="p-0">
                <div className="card">
                    <div className="card-body">
                        {offlineMode && (
                            <div className="alert alert-warning d-flex align-items-center gap-2 mb-4" role="status">
                                <i className="bi bi-cloud-slash" />
                                <span>Se guardará en este dispositivo y se sincronizará automáticamente.</span>
                            </div>
                        )}
                        <Form onSubmit={onSubmit}>
                            <div className="row">

                                {/* Tipo identificación */}
                            <div className="col-md-6 mb-3">
                                    <label className="form-label">
                                        Tipo de identificación:
                                    </label>
                                    <select
                                        name="tipo_identificacion"
                                        className="form-control"
                                        value={customerValue.tipo_identificacion}
                                        onChange={onChangeInput}
                                    >
                                        <option value="05">Cédula</option>
                                        <option value="04">RUC</option>
                                        <option value="06">Pasaporte</option>
                                        <option value="07">Consumidor Final</option>
                                    </select>
                                </div>

                                {/* Identificación con validación y botón SRI */}
                                <IdentificacionField
                                    tipo={customerValue.tipo_identificacion}
                                    value={customerValue.identification}
                                    onChange={onChangeInput}
                                    error={errors.identification}
                                    isEdit={Boolean(singleCustomer)}
                                    sriLoading={sriLoading}
                                    onSriLookup={handleSriLookup}
                                    sriDisabled={offlineMode}
                                />

                                {/* Nombre */}
                                <div className="col-md-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage('globally.input.name.label')}:
                                        <span className="required" />
                                    </label>
                                    <input
                                        type="text"
                                        name="name"
                                        className={`form-control ${errors.name ? 'is-invalid' : ''}`}
                                        placeholder={placeholderText('globally.input.name.placeholder.label')}
                                        value={customerValue.name}
                                        onChange={onChangeInput}
                                        disabled={Boolean(singleCustomer)}
                                        autoFocus
                                    />
                                    {errors.name && (
                                        <span className="text-danger d-block fw-400 fs-small mt-2">
                                            {errors.name}
                                        </span>
                                    )}
                                </div>

                                {/* Email */}
                                <div className="col-md-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage('globally.input.email.label')}:
                                        <span className="required" />
                                    </label>
                                    <input
                                        type="text"
                                        name="email"
                                        className={`form-control ${errors.email ? 'is-invalid' : ''}`}
                                        placeholder={placeholderText('globally.input.email.placeholder.label')}
                                        value={customerValue.email}
                                        onChange={onChangeInput}
                                    />
                                    {errors.email && (
                                        <span className="text-danger d-block fw-400 fs-small mt-2">
                                            {errors.email}
                                        </span>
                                    )}
                                </div>

                                {/* Teléfono */}
                                <div className="col-md-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage('globally.input.phone-number.label')}:
                                        <span className="required" />
                                    </label>
                                    <input
                                        type="text"
                                        name="phone"
                                        className={`form-control ${errors.phone ? 'is-invalid' : ''}`}
                                        placeholder={placeholderText('globally.input.phone-number.placeholder.label')}
                                        onKeyPress={(e) => numValidate(e)}
                                        value={customerValue.phone}
                                        onChange={onChangeInput}
                                    />
                                    {errors.phone && (
                                        <span className="text-danger d-block fw-400 fs-small mt-2">
                                            {errors.phone}
                                        </span>
                                    )}
                                </div>

                                {/* País */}
                                <div className="col-md-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage('globally.input.country.label')}:
                                        <span className="required" />
                                    </label>
                                    <input
                                        type="text"
                                        name="country"
                                        className={`form-control ${errors.country ? 'is-invalid' : ''}`}
                                        placeholder={placeholderText('globally.input.country.placeholder.label')}
                                        value={customerValue.country}
                                        onChange={onChangeInput}
                                    />
                                    {errors.country && (
                                        <span className="text-danger d-block fw-400 fs-small mt-2">
                                            {errors.country}
                                        </span>
                                    )}
                                </div>

                                {/* Ciudad */}
                                <div className="col-md-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage('globally.input.city.label')}:
                                        <span className="required" />
                                    </label>
                                    <input
                                        type="text"
                                        name="city"
                                        className={`form-control ${errors.city ? 'is-invalid' : ''}`}
                                        placeholder={placeholderText('globally.input.city.placeholder.label')}
                                        value={customerValue.city}
                                        onChange={onChangeInput}
                                    />
                                    {errors.city && (
                                        <span className="text-danger d-block fw-400 fs-small mt-2">
                                            {errors.city}
                                        </span>
                                    )}
                                </div>

                                {/* Dirección */}
                                <div className="col-md-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage('globally.input.address.label')}:
                                        <span className="required" />
                                    </label>
                                    <input
                                        type="text"
                                        name="address"
                                        className={`form-control ${errors.address ? 'is-invalid' : ''}`}
                                        placeholder={placeholderText('globally.input.address.placeholder.label')}
                                        value={customerValue.address}
                                        onChange={onChangeInput}
                                    />
                                    {errors.address && (
                                        <span className="text-danger d-block fw-400 fs-small mt-2">
                                            {errors.address}
                                        </span>
                                    )}
                                </div>

                                {!offlineMode && <div className="col-12">
                                <section className={`customer-credit-card ${customerValue.credit_enabled ? "is-enabled" : ""}`}>
                                    <div className="customer-credit-card__heading">
                                        <div className="customer-credit-card__icon"><i className="bi bi-credit-card-2-front" /></div>
                                        <div><span>CRÉDITO</span><h3>Condiciones comerciales</h3><p>Opcional: controla cupo y plazo para ventas pendientes.</p></div>
                                        <label className="customer-credit-switch"><input type="checkbox" name="credit_enabled" checked={Boolean(customerValue.credit_enabled)} onChange={onChangeInput} /><span /><b>{customerValue.credit_enabled ? "Activo" : "Sin control"}</b></label>
                                    </div>
                                    {customerValue.credit_enabled && <div className="customer-credit-fields">
                                        <label>Cupo máximo<div><span>$</span><input type="number" min="0" step="0.01" name="credit_limit" value={customerValue.credit_limit} onChange={onChangeInput} /></div></label>
                                        <label>Plazo habitual<div><input type="number" min="0" max="3650" name="default_payment_terms_days" value={customerValue.default_payment_terms_days} onChange={onChangeInput} /><span>días</span></div></label>
                                    </div>}
                                </section>
                                </div>}

                                <ModelFooter
                                    onEditRecord={customerData}
                                    onSubmit={onSubmit}
                                    editDisabled={isDisabled}
                                    addDisabled={!customerValue.name}
                                    clearField={hide}
                                />
                            </div>
                        </Form>
                    </div>
                </div>
            </Modal.Body>
        </Modal>
    );
};

export default connect(null, { editCustomer, addCustomer })(CustomerForm);
