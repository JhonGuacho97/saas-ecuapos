import React, { useEffect, useMemo, useRef, useState } from 'react';
import Modal from 'react-bootstrap/Modal';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faAngleLeft,
    faAngleRight,
    faMagnifyingGlass,
    faUserPlus,
} from '@fortawesome/free-solid-svg-icons';

const PAGE_SIZE = 8;

const ID_TYPES = {
    '04': 'RUC',
    '05': 'Cédula',
    '06': 'Pasaporte',
    '07': 'Consumidor final',
    '08': 'ID exterior',
};

const normalize = (value) =>
    String(value || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const money = (value) => `$${Number(value || 0).toFixed(2)}`;

// Opción que espera PosMainPage (mismos campos que antes daba el select).
export const toCustomerOption = (customer) => {
    const attrs = customer.attributes || {};
    return {
        value: customer.id,
        label: attrs.name,
        offlineCustomerUuid: attrs.offline_client_uuid || null,
        offlineStatus: attrs.offline_status || null,
        credit_enabled: Boolean(attrs.credit_enabled),
        credit_limit: Number(attrs.credit_limit || 0),
        default_payment_terms_days: Number(attrs.default_payment_terms_days || 0),
        es_consumidor_final: Boolean(attrs.es_consumidor_final),
    };
};

export const findCustomer = (customers, option) => {
    if (!option || !customers?.length) return null;
    return customers.find((item) =>
        String(item.id) === String(option.value)
        || (option.offlineCustomerUuid && item.attributes?.offline_client_uuid === option.offlineCustomerUuid)
    ) || null;
};

// Datos a mostrar de un cliente; si todavía no está en la lista (recién
// creado o cargando) se arma con lo que trae la opción seleccionada.
export const customerFacts = (customer, option) => {
    const attrs = customer?.attributes || {};
    const isFinalConsumer = Boolean(attrs.es_consumidor_final ?? option?.es_consumidor_final);
    const status = attrs.offline_status || option?.offlineStatus;
    let badge = null;
    if (status === 'requires_review') badge = { text: 'Revisar', tone: 'danger' };
    else if (['pending', 'syncing'].includes(status)) badge = { text: 'Sin sincronizar', tone: 'warning' };
    else if (attrs.credit_enabled) badge = { text: `Crédito ${money(attrs.credit_limit)}`, tone: 'info' };

    return {
        name: attrs.name || option?.label || '',
        idLabel: ID_TYPES[attrs.tipo_identificacion] && attrs.tipo_identificacion !== '07'
            ? ID_TYPES[attrs.tipo_identificacion]
            : 'Identificación',
        identification: attrs.identification || (isFinalConsumer ? '9999999999999' : ''),
        phone: attrs.phone || '',
        email: attrs.email || '',
        isFinalConsumer,
        badge,
    };
};

const CustomerSearchModal = ({ customers, initialQuery, selectedId, onSelect, onCreate, onHide }) => {
    const [query, setQuery] = useState(initialQuery || '');
    const [page, setPage] = useState(0);
    const [activeIndex, setActiveIndex] = useState(0);
    const inputRef = useRef(null);

    useEffect(() => {
        const timer = setTimeout(() => inputRef.current?.focus(), 80);
        return () => clearTimeout(timer);
    }, []);

    const results = useMemo(() => {
        const list = customers || [];
        const terms = normalize(query).split(/\s+/).filter(Boolean);
        if (!terms.length) return list;
        return list.filter((item) => {
            const attrs = item.attributes || {};
            const haystack = normalize(`${attrs.name} ${attrs.identification} ${attrs.phone} ${attrs.email}`);
            return terms.every((term) => haystack.includes(term));
        });
    }, [customers, query]);

    const pageCount = Math.max(1, Math.ceil(results.length / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount - 1);
    const pageItems = results.slice(currentPage * PAGE_SIZE, currentPage * PAGE_SIZE + PAGE_SIZE);
    const from = results.length ? currentPage * PAGE_SIZE + 1 : 0;
    const to = currentPage * PAGE_SIZE + pageItems.length;

    useEffect(() => {
        setPage(0);
        setActiveIndex(0);
    }, [query]);

    const goToPage = (next) => {
        setPage(Math.min(Math.max(next, 0), pageCount - 1));
        setActiveIndex(0);
    };

    const isBlocked = (item) => item.attributes?.offline_status === 'requires_review';

    const choose = (item) => {
        if (item && !isBlocked(item)) onSelect(item);
    };

    const onKeyDown = (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (activeIndex < pageItems.length - 1) setActiveIndex(activeIndex + 1);
            else if (currentPage < pageCount - 1) goToPage(currentPage + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (activeIndex > 0) setActiveIndex(activeIndex - 1);
            else if (currentPage > 0) {
                setPage(currentPage - 1);
                setActiveIndex(PAGE_SIZE - 1);
            }
        } else if (event.key === 'Enter') {
            event.preventDefault();
            choose(pageItems[activeIndex]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            onHide();
        }
    };

    const digitsOnly = /^\d{5,}$/.test(query.trim());

    return (
        <Modal show onHide={onHide} centered size="lg" className="pos-modal pos-client-modal">
            <Modal.Header closeButton>
                <div>
                    <span className="pos-client-modal__eyebrow">CLIENTES</span>
                    <Modal.Title>Buscar cliente</Modal.Title>
                </div>
            </Modal.Header>
            <Modal.Body>
                <div className="pos-client-modal__toolbar">
                    <label className="pos-client-modal__search">
                        <FontAwesomeIcon icon={faMagnifyingGlass} />
                        <input
                            ref={inputRef}
                            type="text"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder="Nombre, cédula, RUC, teléfono o correo"
                            aria-label="Buscar cliente"
                        />
                    </label>
                    <button type="button" className="pos-client-modal__new" onClick={() => onCreate(digitsOnly ? query.trim() : '')}>
                        <FontAwesomeIcon icon={faUserPlus} />
                        <span>Nuevo cliente</span>
                    </button>
                </div>

                {pageItems.length === 0 ? (
                    <div className="pos-client-modal__empty">
                        <strong>No encontramos clientes con “{query}”.</strong>
                        <span>Revisa lo que escribiste o regístralo como cliente nuevo.</span>
                        <button type="button" onClick={() => onCreate(digitsOnly ? query.trim() : '')}>
                            <FontAwesomeIcon icon={faUserPlus} />
                            {digitsOnly ? `Crear cliente con ${query.trim()}` : 'Crear cliente nuevo'}
                        </button>
                    </div>
                ) : (
                    <div className="pos-client-modal__list" role="listbox" aria-label="Clientes">
                        <div className="pos-client-modal__head" aria-hidden="true">
                            <span>Cliente</span>
                            <span>Identificación</span>
                            <span className="pos-client-modal__credit-col">Crédito</span>
                        </div>
                        {pageItems.map((item, index) => {
                            const facts = customerFacts(item);
                            const blocked = isBlocked(item);
                            const isCurrent = String(item.id) === String(selectedId);
                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    role="option"
                                    aria-selected={isCurrent}
                                    disabled={blocked}
                                    className={`pos-client-modal__row${index === activeIndex ? ' is-active' : ''}${isCurrent ? ' is-current' : ''}`}
                                    onMouseEnter={() => setActiveIndex(index)}
                                    onClick={() => choose(item)}
                                >
                                    <span className="pos-client-modal__who">
                                        <strong>{facts.name}</strong>
                                        <small>{[facts.phone, facts.email].filter(Boolean).join(' · ') || 'Sin teléfono ni correo'}</small>
                                    </span>
                                    <span className="pos-client-modal__id">
                                        <strong>{facts.identification || '—'}</strong>
                                        <small>{facts.isFinalConsumer ? 'Consumidor final' : facts.idLabel}</small>
                                    </span>
                                    <span className="pos-client-modal__credit-col">
                                        {facts.badge
                                            ? <em className={`pos-client__badge is-${facts.badge.tone}`}>{facts.badge.text}</em>
                                            : <small>Sin crédito</small>}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                )}

                <div className="pos-client-modal__footer">
                    <span>{from}–{to} de {results.length} clientes</span>
                    <div className="pos-client-modal__pager">
                        <button type="button" onClick={() => goToPage(currentPage - 1)} disabled={currentPage === 0} aria-label="Página anterior">
                            <FontAwesomeIcon icon={faAngleLeft} />
                        </button>
                        <span>{currentPage + 1} / {pageCount}</span>
                        <button type="button" onClick={() => goToPage(currentPage + 1)} disabled={currentPage >= pageCount - 1} aria-label="Página siguiente">
                            <FontAwesomeIcon icon={faAngleRight} />
                        </button>
                    </div>
                </div>
                <p className="pos-client-modal__hint">↑ ↓ para moverte · Enter para elegir · F2 abre este buscador</p>
            </Modal.Body>
        </Modal>
    );
};

export default CustomerSearchModal;
