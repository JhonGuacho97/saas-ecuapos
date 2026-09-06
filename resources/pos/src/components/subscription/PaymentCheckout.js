import React, { useMemo, useRef, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faArrowLeft, faBuilding, faCamera, faCheck, faCloudArrowUp,
    faCreditCard, faMoneyBillWave, faShieldHalved,
} from '@fortawesome/free-solid-svg-icons';
import apiConfig from '../../config/apiConfig';
import { interval, money, planFeatures } from './subscriptionHelpers';
import './subscription-access-gate.scss';

const createSubmissionKey = () => (window.crypto?.randomUUID?.()
    || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
        const random = Math.floor(Math.random() * 16);
        return (character === 'x' ? random : (random & 0x3) | 0x8).toString(16);
    }));

/**
 * Solicitud de pago manual: método, datos de facturación y foto del
 * comprobante. El acceso no se habilita acá -- queda PENDING hasta que
 * un super admin verifique el comprobante.
 */
export default function PaymentCheckout({ plan, onBack, onSubmitted }) {
    const [method, setMethod] = useState('transfer');
    const [proof, setProof] = useState(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');
    const submissionKey = useRef(createSubmissionKey());
    const user = useMemo(() => {
        try { return JSON.parse(localStorage.getItem('loginUserArray') || '{}'); } catch (_) { return {}; }
    }, []);
    const [billing, setBilling] = useState({
        billing_name: [user.first_name, user.last_name].filter(Boolean).join(' '),
        billing_tax_id: '', billing_email: user.email || '', billing_phone: user.phone || '', billing_address: '',
    });

    const submit = async event => {
        event.preventDefault();
        if (!proof) { setError('Adjunta una foto clara del comprobante de pago.'); return; }
        setSending(true); setError('');
        const body = new FormData();
        body.append('saas_plan_id', plan.id);
        body.append('method', method);
        body.append('proof', proof);
        body.append('submission_key', submissionKey.current);
        Object.entries(billing).forEach(([key, value]) => body.append(key, value || ''));
        try {
            await apiConfig.post('subscription-portal/payments', body);
            await onSubmitted();
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'No pudimos enviar el comprobante. Intenta nuevamente.');
        } finally { setSending(false); }
    };

    return <>
        <button className="sub-back" onClick={() => { setError(''); onBack(); }}>
            <FontAwesomeIcon icon={faArrowLeft} /> Volver a los planes
        </button>
        <div className="sub-checkout">
            <section className="sub-checkout-main">
                <span className="sub-kicker">RENUEVA ECUAPOS</span>
                <h1>Completa tu solicitud de pago</h1>
                <p>Selecciona cómo realizaste el pago y adjunta un comprobante legible.</p>
                <div className="sub-methods">
                    <button type="button" className={method === 'transfer' ? 'active' : ''} onClick={() => setMethod('transfer')}><FontAwesomeIcon icon={faCreditCard} /><strong>Transferencia</strong><small>Transferencia bancaria</small></button>
                    <button type="button" className={method === 'deposit' ? 'active' : ''} onClick={() => setMethod('deposit')}><FontAwesomeIcon icon={faBuilding} /><strong>Depósito</strong><small>Depósito en ventanilla</small></button>
                    <button type="button" className={method === 'cash' ? 'active' : ''} onClick={() => setMethod('cash')}><FontAwesomeIcon icon={faMoneyBillWave} /><strong>Efectivo</strong><small>Pago directo autorizado</small></button>
                </div>
                <div className="sub-bank-note"><FontAwesomeIcon icon={faShieldHalved} /><div><strong>Pago sujeto a verificación</strong><span>Realiza el pago en la cuenta informada por EcuaPos. El plan se activa únicamente después de comprobarlo.</span></div></div>
                <form onSubmit={submit}>
                    <div className="sub-form-grid">
                        <label><span>Nombre o razón social</span><input required value={billing.billing_name} onChange={e => setBilling({ ...billing, billing_name: e.target.value })} /></label>
                        <label><span>Cédula / RUC</span><input required value={billing.billing_tax_id} onChange={e => setBilling({ ...billing, billing_tax_id: e.target.value })} /></label>
                        <label><span>Correo de facturación</span><input type="email" required value={billing.billing_email} onChange={e => setBilling({ ...billing, billing_email: e.target.value })} /></label>
                        <label><span>Teléfono</span><input value={billing.billing_phone} onChange={e => setBilling({ ...billing, billing_phone: e.target.value })} /></label>
                        <label className="wide"><span>Dirección</span><input value={billing.billing_address} onChange={e => setBilling({ ...billing, billing_address: e.target.value })} /></label>
                    </div>
                    <label className={`sub-upload ${proof ? 'has-file' : ''}`}>
                        <input type="file" accept="image/jpeg,image/png,image/webp" onChange={e => setProof(e.target.files?.[0] || null)} />
                        <FontAwesomeIcon icon={proof ? faCheck : faCloudArrowUp} />
                        <strong>{proof ? proof.name : 'Sube la foto del comprobante'}</strong>
                        <span>JPG, PNG o WEBP · máximo 5 MB</span>
                        <b><FontAwesomeIcon icon={faCamera} /> Elegir imagen</b>
                    </label>
                    {error && <div className="sub-error">{error}</div>}
                    <button className="sub-btn sub-btn--primary sub-submit" disabled={sending}>{sending ? 'Enviando comprobante…' : 'Enviar para revisión'}</button>
                </form>
            </section>
            <aside className="sub-order">
                <span className="sub-kicker">RESUMEN</span><h2>{plan.name}</h2>
                <p>{plan.description || 'Plan comercial EcuaPos.'}</p>
                <ul>{planFeatures(plan).map(feature => <li key={feature}><FontAwesomeIcon icon={faCheck} /> {feature}</li>)}</ul>
                <div className="sub-order-total"><span>Total a pagar</span><strong>{money(plan.price, plan.currency)}</strong><small>por {interval(plan)}</small></div>
            </aside>
        </div>
    </>;
}
