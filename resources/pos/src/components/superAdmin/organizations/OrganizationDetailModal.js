import React, { useEffect, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBuilding, faStore, faUsers, faWarehouse, faXmark } from '@fortawesome/free-solid-svg-icons';
import api from '../api/superAdminApi';
import { formatDate, formatMoney, subscriptionStatusLabels } from '../utils/formatters';

const Detail = ({ label, value }) => <div className="sa-detail-item"><span>{label}</span><strong>{value || '—'}</strong></div>;

export default function OrganizationDetailModal({ organization, onClose }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get(`organizations/${organization.id}`)
            .then(setData)
            .catch(requestError => setError(requestError.response?.data?.message || 'No se pudo cargar la organización.'));
    }, [organization.id]);

    const subscription = data?.subscription;

    return <div className="sa-modal-backdrop" role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose()}>
        <section className="sa-modal sa-detail-modal" role="dialog" aria-modal="true" aria-labelledby="organization-detail-title">
            <header>
                <div><span className="sa-eyebrow">EXPEDIENTE DE CLIENTE</span><h2 id="organization-detail-title">{organization.name}</h2></div>
                <button type="button" onClick={onClose} aria-label="Cerrar"><FontAwesomeIcon icon={faXmark} /></button>
            </header>
            <div className="sa-modal-body">
                {error ? <div className="sa-form-error">{error}</div> : !data ? <div className="sa-loading"><span /> Cargando información…</div> : <>
                    <div className="sa-detail-hero">
                        <span className="sa-detail-hero__icon"><FontAwesomeIcon icon={faBuilding} /></span>
                        <div><small>Organización #{data.id}</small><strong>{data.name}</strong><span>{data.slug}</span></div>
                        <span className={`sa-status sa-status--${data.is_active ? 'active' : 'suspended'}`}>{data.is_active ? 'Activa' : 'Suspendida'}</span>
                    </div>
                    <section className="sa-detail-section">
                        <header><h3>Resumen comercial</h3><span>Estado y suscripción</span></header>
                        <div className="sa-detail-grid">
                            <Detail label="Plan" value={subscription?.plan?.name || 'Sin plan'} />
                            <Detail label="Suscripción" value={subscriptionStatusLabels[subscription?.status] || subscription?.status || 'Sin suscripción'} />
                            <Detail label="Inicio" value={formatDate(subscription?.starts_at)} />
                            <Detail label="Vencimiento" value={formatDate(subscription?.trial_ends_at || subscription?.current_period_ends_at)} />
                            <Detail label="Pagos registrados" value={String(data.payments_count || 0)} />
                            <Detail label="Total pagado" value={formatMoney(data.paid_total || 0, subscription?.plan?.currency)} />
                        </div>
                    </section>
                    {!data.is_active && <div className="sa-detail-alert"><strong>Motivo de suspensión</strong><span>{data.suspension_reason || 'No especificado'} · {formatDate(data.suspended_at)}</span>{data.suspension_note && <p>{data.suspension_note}</p>}</div>}
                    <section className="sa-detail-section">
                        <header><h3>Estructura operativa</h3><span>{data.stores_count} tiendas · {data.users_count} usuarios</span></header>
                        <div className="sa-detail-store-list">
                            {data.stores.length ? data.stores.map(store => <article key={store.id}>
                                <span><FontAwesomeIcon icon={faStore} /></span>
                                <div><strong>{store.name}</strong><small>{store.slug}{store.is_default ? ' · Principal' : ''}</small></div>
                                <div><b><FontAwesomeIcon icon={faUsers} /> {store.users_count}</b><b><FontAwesomeIcon icon={faWarehouse} /> {store.warehouses_count}</b></div>
                            </article>) : <p className="sa-muted">Esta organización todavía no tiene tiendas.</p>}
                        </div>
                    </section>
                    <section className="sa-detail-section">
                        <header><h3>Usuarios vinculados</h3><span>{data.users_count} en total</span></header>
                        <div className="sa-detail-member-list">
                            {data.users.length ? data.users.map(user => <article key={user.id}>
                                <span>{user.first_name?.charAt(0)}</span>
                                <div><strong>{user.first_name} {user.last_name}</strong><small>{user.email}</small></div>
                                <b>{user.pivot?.role || 'MEMBER'}</b>
                            </article>) : <p className="sa-muted">No existen usuarios vinculados.</p>}
                        </div>
                    </section>
                </>}
            </div>
            <footer><button type="button" className="sa-btn sa-btn--primary" onClick={onClose}>Cerrar</button></footer>
        </section>
    </div>;
}
