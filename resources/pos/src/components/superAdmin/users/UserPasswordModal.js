import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faKey, faTriangleExclamation, faXmark } from '@fortawesome/free-solid-svg-icons';
import api from '../api/superAdminApi';

export default function UserPasswordModal({ user, onClose, onSaved }) {
    const [form, setForm] = useState({ password: '', password_confirmation: '' });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const submit = async event => {
        event.preventDefault();
        setError('');
        if (form.password !== form.password_confirmation) {
            setError('Las contraseñas no coinciden.');
            return;
        }
        setSaving(true);
        try {
            const response = await api.patch(`users/${user.id}/password`, form);
            onSaved(response.message);
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'No se pudo actualizar la contraseña.');
        } finally {
            setSaving(false);
        }
    };

    return <div className="sa-modal-backdrop sa-security-backdrop" role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose()}>
        <section className="sa-modal sa-password-modal" role="dialog" aria-modal="true" aria-labelledby="user-password-title">
            <header>
                <div><span className="sa-eyebrow">RECUPERACIÓN DE ACCESO</span><h2 id="user-password-title">Cambiar contraseña</h2><p>{user.first_name} {user.last_name} · {user.email}</p></div>
                <button type="button" onClick={onClose} aria-label="Cerrar"><FontAwesomeIcon icon={faXmark} /></button>
            </header>
            <form onSubmit={submit}>
                <div className="sa-modal-body">
                    <div className="sa-security-required"><FontAwesomeIcon icon={faKey} /><div><strong>Las sesiones abiertas serán cerradas</strong><span>El usuario deberá volver a iniciar sesión con la nueva contraseña.</span></div></div>
                    {error && <div className="sa-form-error"><FontAwesomeIcon icon={faTriangleExclamation} /> {error}</div>}
                    <div className="sa-password-grid">
                        <label className="sa-field"><span>Nueva contraseña</span><input type="password" minLength="8" autoComplete="new-password" value={form.password} onChange={event => setForm({ ...form, password: event.target.value })} required /></label>
                        <label className="sa-field"><span>Confirmar contraseña</span><input type="password" minLength="8" autoComplete="new-password" value={form.password_confirmation} onChange={event => setForm({ ...form, password_confirmation: event.target.value })} required /></label>
                    </div>
                </div>
                <footer><button type="button" className="sa-btn sa-btn--soft" onClick={onClose} disabled={saving}>Cancelar</button><button type="submit" className="sa-btn sa-btn--primary" disabled={saving || form.password.length < 8}>{saving ? 'Actualizando…' : 'Guardar contraseña'}</button></footer>
            </form>
        </section>
    </div>;
}
