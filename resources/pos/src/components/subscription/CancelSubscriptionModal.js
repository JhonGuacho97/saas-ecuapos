import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTriangleExclamation, faXmark } from '@fortawesome/free-solid-svg-icons';
import apiConfig from '../../config/apiConfig';
import { shortDate } from './subscriptionHelpers';

const REASONS = [
    ['TOO_EXPENSIVE', 'El precio no se ajusta a mi presupuesto'],
    ['MISSING_FEATURES', 'Necesito funcionalidades diferentes'],
    ['NOT_USING', 'No estoy utilizando el sistema'],
    ['TECHNICAL_ISSUES', 'He tenido problemas técnicos'],
    ['BUSINESS_CLOSED', 'Cierre temporal o definitivo del negocio'],
    ['OTHER', 'Otro motivo'],
];

export default function CancelSubscriptionModal({ endsAt, onClose, onCanceled }) {
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = async event => {
        event.preventDefault();
        setSaving(true);
        setError('');
        try {
            const response = await apiConfig.post('subscription-portal/cancel', { reason, note: note.trim() || null });
            await onCanceled(response.data.message);
        } catch (requestError) {
            const validation = requestError.response?.data?.errors;
            setError(validation ? Object.values(validation).flat()[0] : requestError.response?.data?.message || 'No pudimos programar la cancelación.');
        } finally {
            setSaving(false);
        }
    };

    return <div className="sm-modal-backdrop" onMouseDown={event => event.target === event.currentTarget && onClose()}>
        <form className="sm-cancel-modal" onSubmit={submit} role="dialog" aria-modal="true" aria-labelledby="cancel-subscription-title">
            <header>
                <span className="sm-cancel-modal__icon"><FontAwesomeIcon icon={faTriangleExclamation} /></span>
                <div>
                    <span className="sub-kicker">CONFIRMA TU DECISIÓN</span>
                    <h2 id="cancel-subscription-title">Cancelar suscripción</h2>
                </div>
                <button type="button" onClick={onClose} aria-label="Cerrar"><FontAwesomeIcon icon={faXmark} /></button>
            </header>
            <div className="sm-cancel-modal__body">
                <p>Tu acceso continuará activo hasta <strong>{shortDate(endsAt)}</strong>. Después de esa fecha el plan no se renovará y tus datos permanecerán guardados.</p>
                <label>
                    <span>¿Cuál es el motivo?</span>
                    <select value={reason} onChange={event => setReason(event.target.value)} required>
                        <option value="">Selecciona un motivo</option>
                        {REASONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                </label>
                <label>
                    <span>Cuéntanos un poco más <small>{reason === 'OTHER' ? '(obligatorio)' : '(opcional)'}</small></span>
                    <textarea value={note} onChange={event => setNote(event.target.value)} maxLength={1000} required={reason === 'OTHER'} placeholder="Tu comentario nos ayuda a mejorar EcuaPOS." />
                </label>
                {error && <div className="sub-error">{error}</div>}
            </div>
            <footer>
                <button type="button" className="sub-btn sub-btn--soft" onClick={onClose}>Volver</button>
                <button type="submit" className="sub-btn sub-btn--danger" disabled={saving || !reason}>{saving ? 'Programando…' : 'Confirmar cancelación'}</button>
            </footer>
        </form>
    </div>;
}
