import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faKey, faMobileScreenButton, faShieldHalved, faTriangleExclamation, faXmark } from '@fortawesome/free-solid-svg-icons';
import api from '../api/superAdminApi';

export default function SuperAdminPasswordModal({ show, onClose, onSaved }) {
    const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '', code: '' });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    if (!show) return null;

    const set = (key, value) => setForm(current => ({ ...current, [key]: value }));
    const submit = async event => {
        event.preventDefault();
        setError('');
        if (form.password !== form.password_confirmation) {
            setError('Las contraseñas nuevas no coinciden.');
            return;
        }
        setSaving(true);
        try {
            const response = await api.patch('security/password', form);
            setForm({ current_password: '', password: '', password_confirmation: '', code: '' });
            onSaved(response.message);
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'No se pudo actualizar la contraseña.');
        } finally {
            setSaving(false);
        }
    };

    return <div className="sa-modal-backdrop sa-security-backdrop" role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose()}>
        <section className="sa-modal sa-password-modal" role="dialog" aria-modal="true" aria-labelledby="super-admin-password-title">
            <header><div><span className="sa-eyebrow">SEGURIDAD DE LA CUENTA</span><h2 id="super-admin-password-title">Cambiar contraseña</h2><p>Confirma tu identidad antes de guardar el cambio.</p></div><button type="button" onClick={onClose} aria-label="Cerrar"><FontAwesomeIcon icon={faXmark} /></button></header>
            <form onSubmit={submit}>
                <div className="sa-modal-body">
                    <div className="sa-security-required"><FontAwesomeIcon icon={faShieldHalved} /><div><strong>Verificación en dos pasos obligatoria</strong><span>Necesitas tu contraseña actual y un código 2FA nuevo. Las demás sesiones se cerrarán.</span></div></div>
                    {error && <div className="sa-form-error"><FontAwesomeIcon icon={faTriangleExclamation} /> {error}</div>}
                    <div className="sa-password-grid">
                        <label className="sa-field sa-field--wide"><span>Contraseña actual</span><input type="password" autoComplete="current-password" value={form.current_password} onChange={event => set('current_password', event.target.value)} required /></label>
                        <label className="sa-field"><span>Nueva contraseña</span><input type="password" minLength="8" autoComplete="new-password" value={form.password} onChange={event => set('password', event.target.value)} required /></label>
                        <label className="sa-field"><span>Confirmar contraseña</span><input type="password" minLength="8" autoComplete="new-password" value={form.password_confirmation} onChange={event => set('password_confirmation', event.target.value)} required /></label>
                        <label className="sa-field sa-field--wide"><span><FontAwesomeIcon icon={faMobileScreenButton} /> Código de autenticación</span><input type="text" inputMode="numeric" autoComplete="one-time-code" maxLength="6" placeholder="000000" value={form.code} onChange={event => set('code', event.target.value.replace(/\D/g, '').slice(0, 6))} required /></label>
                    </div>
                </div>
                <footer><button type="button" className="sa-btn sa-btn--soft" onClick={onClose} disabled={saving}>Cancelar</button><button type="submit" className="sa-btn sa-btn--primary" disabled={saving || form.password.length < 8 || form.code.length !== 6}>{saving ? 'Actualizando…' : 'Actualizar contraseña'}</button></footer>
            </form>
        </section>
    </div>;
}
