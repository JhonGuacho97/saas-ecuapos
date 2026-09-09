import React, { useEffect, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faKey, faStore, faWarehouse, faXmark } from '@fortawesome/free-solid-svg-icons';
import api from '../api/superAdminApi';
import { formatDate } from '../utils/formatters';

const Detail = ({ label, value }) => <div className="sa-detail-item"><span>{label}</span><strong>{value || '—'}</strong></div>;

export default function UserDetailModal({ user, onClose, onResetPassword }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get(`users/${user.id}`).then(setData)
            .catch(requestError => setError(requestError.response?.data?.message || 'No se pudo cargar el usuario.'));
    }, [user.id]);

    return <div className="sa-modal-backdrop" role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose()}>
        <section className="sa-modal sa-detail-modal" role="dialog" aria-modal="true" aria-labelledby="user-detail-title">
            <header>
                <div><span className="sa-eyebrow">USUARIO DE ORGANIZACIÓN</span><h2 id="user-detail-title">{user.first_name} {user.last_name}</h2></div>
                <button type="button" onClick={onClose} aria-label="Cerrar"><FontAwesomeIcon icon={faXmark} /></button>
            </header>
            <div className="sa-modal-body">
                {error ? <div className="sa-form-error">{error}</div> : !data ? <div className="sa-loading"><span /> Cargando información…</div> : <>
                    <div className="sa-detail-hero">
                        <span className="sa-detail-avatar">{data.first_name?.charAt(0)}{data.last_name?.charAt(0)}</span>
                        <div><small>Usuario #{data.id}</small><strong>{data.first_name} {data.last_name}</strong><span>{data.email}</span></div>
                        <span className={`sa-status sa-status--${data.status ? 'active' : 'suspended'}`}>{data.status ? 'Activo' : 'Suspendido'}</span>
                    </div>
                    <section className="sa-detail-section">
                        <header><h3>Información de la cuenta</h3></header>
                        <div className="sa-detail-grid">
                            <Detail label="Correo" value={data.email} />
                            <Detail label="Teléfono" value={data.phone} />
                            <Detail label="Idioma" value={data.language?.toUpperCase()} />
                            <Detail label="Correo verificado" value={data.email_verified_at ? formatDate(data.email_verified_at) : 'Pendiente'} />
                            <Detail label="Registrado" value={formatDate(data.created_at)} />
                            <Detail label="Última actualización" value={formatDate(data.updated_at)} />
                        </div>
                    </section>
                    <section className="sa-detail-section">
                        <header><h3>Organizaciones</h3><span>{data.organizations.length} vinculadas</span></header>
                        <div className="sa-detail-tags">{data.organizations.map(item => <span key={item.id}>{item.name}<small>{item.pivot?.role || 'MEMBER'}</small></span>)}</div>
                    </section>
                    <section className="sa-detail-section">
                        <header><h3>Acceso operativo</h3><span>{data.stores.length} tiendas · {data.warehouses.length} almacenes restringidos</span></header>
                        <div className="sa-detail-access-list">
                            {data.stores.map(item => <span key={`store-${item.id}`}><FontAwesomeIcon icon={faStore} /> {item.name}</span>)}
                            {data.warehouses.map(item => <span key={`warehouse-${item.id}`}><FontAwesomeIcon icon={faWarehouse} /> {item.name}</span>)}
                            {!data.stores.length && !data.warehouses.length && <p className="sa-muted">No tiene accesos operativos asignados.</p>}
                        </div>
                    </section>
                </>}
            </div>
            <footer>
                <button type="button" className="sa-btn sa-btn--soft" onClick={onClose}>Cerrar</button>
                <button type="button" className="sa-btn sa-btn--primary" onClick={() => onResetPassword(data || user)} disabled={!data}><FontAwesomeIcon icon={faKey} /> Cambiar contraseña</button>
            </footer>
        </section>
    </div>;
}
