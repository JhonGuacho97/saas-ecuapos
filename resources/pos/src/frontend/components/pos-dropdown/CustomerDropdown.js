import React, { useEffect, useMemo, useState } from 'react';
import { connect } from 'react-redux';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faAddressCard,
    faMagnifyingGlass,
    faUserPlus,
    faUserGroup,
    faXmark,
} from '@fortawesome/free-solid-svg-icons';
import { fetchAllCustomer } from '../../../store/action/customerAction';
import { addToast } from '../../../store/action/toastAction';
import { getOfflineCustomer, OFFLINE_CUSTOMERS_EVENT } from '../../../offline/catalogStorage';
import CustomerSearchModal, { customerFacts, findCustomer, toCustomerOption } from './CustomerSearchModal';
import './pos-customer-panel.scss';

// Tipo de identificación según lo que se escribió en el buscador rápido:
// 13 dígitos es RUC, 10 es cédula y cualquier otra cosa se trata como
// pasaporte (el formulario valida el formato).
const guessIdentificationType = (value) => {
    if (/^\d{13}$/.test(value)) return '04';
    if (/^\d{10}$/.test(value)) return '05';
    return '06';
};

const initials = (name = '') =>
    name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || '?';

const CustomerDropDown = (props) => {
    const {
        setSelectedCustomerOption,
        selectedCustomerOption,
        fetchAllCustomer,
        customers,
        settings,
        customerModel,
        offlineMode,
        addToast,
        warehouseSlot,
    } = props;
    const [quickValue, setQuickValue] = useState('');
    const [searchOpen, setSearchOpen] = useState(false);
    const [searchSeed, setSearchSeed] = useState('');

    useEffect(() => {
        const loadCustomers = async () => {
            await fetchAllCustomer();
            if (!selectedCustomerOption?.offlineCustomerUuid) return;
            const localCustomer = await getOfflineCustomer(selectedCustomerOption.offlineCustomerUuid).catch(() => null);
            if (localCustomer?.status === 'synced' && localCustomer.serverCustomerId) {
                setSelectedCustomerOption({
                    value: localCustomer.serverCustomerId,
                    label: localCustomer.payload?.name || selectedCustomerOption.label,
                });
            }
        };
        const handleWorkerMessage = (event) => {
            if (event.data?.type === 'OFFLINE_CUSTOMERS_STATUS_CHANGED') loadCustomers();
        };
        loadCustomers();
        window.addEventListener('online', loadCustomers);
        window.addEventListener(OFFLINE_CUSTOMERS_EVENT, loadCustomers);
        navigator.serviceWorker?.addEventListener('message', handleWorkerMessage);
        return () => {
            window.removeEventListener('online', loadCustomers);
            window.removeEventListener(OFFLINE_CUSTOMERS_EVENT, loadCustomers);
            navigator.serviceWorker?.removeEventListener('message', handleWorkerMessage);
        };
    }, [selectedCustomerOption?.offlineCustomerUuid]);

    // F2 abre el buscador de clientes desde cualquier parte del POS.
    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'F2') {
                event.preventDefault();
                openSearch('');
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const selected = Array.isArray(selectedCustomerOption) ? selectedCustomerOption[0] : selectedCustomerOption;
    const customer = useMemo(() => findCustomer(customers, selected), [customers, selected]);
    const facts = customerFacts(customer, selected);

    const defaultCustomerId = settings?.attributes?.default_customer;
    const isDefault = facts.isFinalConsumer || String(selected?.value) === String(defaultCustomerId);

    const openSearch = (seed) => {
        setQuickValue('');
        setSearchSeed(seed);
        setSearchOpen(true);
    };

    const selectCustomer = (record) => {
        setSelectedCustomerOption(toCustomerOption(record));
        setSearchOpen(false);
        setQuickValue('');
    };

    const createCustomer = (identification = '') => {
        setSearchOpen(false);
        setQuickValue('');
        customerModel(true, identification
            ? { identification, tipo_identificacion: guessIdentificationType(identification) }
            : null);
    };

    const resetToFinalConsumer = () => {
        if (!settings?.attributes) return;
        setSelectedCustomerOption({
            value: Number(settings.attributes.default_customer),
            label: settings.attributes.customer_name,
            es_consumidor_final: true,
        });
    };

    // Campo "Cédula o RUC": si el número ya es de un cliente, lo selecciona;
    // si no, abre el alta de cliente con ese número (y la consulta al SRI).
    // La lista de clientes se abre con la lupa de la tarjeta o con F2.
    const onQuickSubmit = (event) => {
        event.preventDefault();
        const value = quickValue.trim();
        const match = value
            ? (customers || []).find((item) => (item.attributes?.identification || '') === value)
            : null;
        if (!match) {
            createCustomer(value);
            return;
        }
        if (match.attributes?.offline_status === 'requires_review') {
            addToast({ text: 'Ese cliente tiene datos por revisar antes de usarlo.', type: 'error' });
            return;
        }
        selectCustomer(match);
    };

    return (
        <div className={`pos-client ${offlineMode ? 'is-offline' : ''}`}>
            <div className="pos-client__card">
                <span className={`pos-client__avatar ${isDefault ? 'is-default' : ''}`} aria-hidden="true">
                    {isDefault ? <FontAwesomeIcon icon={faUserGroup} /> : initials(facts.name)}
                </span>
                <div className="pos-client__info">
                    <div className="pos-client__name-row">
                        <strong className="pos-client__name" title={facts.name}>{facts.name || 'Seleccionar cliente'}</strong>
                        {facts.badge && <span className={`pos-client__badge is-${facts.badge.tone}`}>{facts.badge.text}</span>}
                    </div>
                    <div className="pos-client__meta">
                        <span>{facts.idLabel}: <b>{facts.identification || '—'}</b></span>
                        <span title={facts.email}>{facts.phone || 'Sin teléfono'} · {facts.email || 'Sin correo'}</span>
                    </div>
                </div>
                <div className="pos-client__actions">
                    {!isDefault && (
                        <button type="button" className="pos-client__icon-btn" onClick={resetToFinalConsumer}
                            title="Volver a consumidor final" aria-label="Volver a consumidor final">
                            <FontAwesomeIcon icon={faXmark} />
                        </button>
                    )}
                    <button type="button" className="pos-client__icon-btn" onClick={() => openSearch('')}
                        title="Buscar cliente (F2)" aria-label="Buscar cliente">
                        <FontAwesomeIcon icon={faMagnifyingGlass} />
                    </button>
                    <button type="button" className="pos-client__icon-btn is-primary" onClick={() => createCustomer()}
                        title={offlineMode ? 'Crear cliente sin conexión' : 'Crear cliente'} aria-label="Crear cliente">
                        <FontAwesomeIcon icon={faUserPlus} />
                    </button>
                </div>
            </div>

            <div className="pos-client__row">
                <form className="pos-client__quick" onSubmit={onQuickSubmit} role="search">
                    <FontAwesomeIcon icon={faAddressCard} className="pos-client__quick-icon" />
                    <input
                        type="text"
                        inputMode="search"
                        value={quickValue}
                        onChange={(event) => setQuickValue(event.target.value)}
                        placeholder="Cédula o RUC"
                        aria-label="Cédula o RUC del cliente"
                    />
                    <button type="submit" title="Buscar o registrar cliente" aria-label="Buscar o registrar cliente">
                        <FontAwesomeIcon icon={faMagnifyingGlass} />
                    </button>
                </form>
                <div className="pos-client__warehouse">{warehouseSlot}</div>
            </div>

            {searchOpen && (
                <CustomerSearchModal
                    customers={customers}
                    initialQuery={searchSeed}
                    selectedId={selected?.value}
                    onSelect={selectCustomer}
                    onCreate={createCustomer}
                    onHide={() => setSearchOpen(false)}
                />
            )}
        </div>
    );
};

const mapStateToProps = (state) => {
    const { customers, settings } = state;
    return { customers, settings };
};
export default connect(mapStateToProps, { fetchAllCustomer, addToast })(CustomerDropDown);
