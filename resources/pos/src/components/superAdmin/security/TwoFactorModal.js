import React, { useCallback, useEffect, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faCheckCircle,
    faClipboard,
    faKey,
    faMobileScreenButton,
    faShieldHalved,
    faTriangleExclamation,
    faXmark,
} from '@fortawesome/free-solid-svg-icons';
import { Tokens } from '../../../constants';
import api from '../api/superAdminApi';

const initialDisableData = { password: '', code: '' };

export default function TwoFactorModal({
    show,
    required = false,
    status,
    onClose,
    onStatusChange,
    setNotice,
}) {
    const [setup, setSetup] = useState(null);
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState([]);
    const [disableData, setDisableData] = useState(initialDisableData);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [setupAttempted, setSetupAttempted] = useState(false);

    const startSetup = useCallback(async () => {
        setSetupAttempted(true);
        setBusy(true);
        setError('');
        try {
            const response = await api.post('security/two-factor/setup');
            setSetup(response.data);
            setRecoveryCodes([]);
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'No se pudo iniciar la configuración.');
        } finally {
            setBusy(false);
        }
    }, []);

    useEffect(() => {
        if (!show) return;
        setCode('');
        setDisableData(initialDisableData);
        setError('');

        // Después de confirmar, React puede renderizar una vez con el estado
        // anterior del padre (enabled=false) mientras ya se están mostrando
        // los códigos de recuperación. Sin esta guarda se iniciaba un segundo
        // setup y el backend, al detectar 2FA activo, respondía "password is
        // required". Los códigos representan una confirmación completada y
        // nunca deben disparar un nuevo enrolamiento.
        if (!status?.enabled && !setup && recoveryCodes.length === 0 && !setupAttempted) startSetup();
    }, [show, status?.enabled, setup, recoveryCodes.length, setupAttempted, startSetup]);

    const close = () => {
        if (required || busy) return;
        setSetup(null);
        setRecoveryCodes([]);
        setSetupAttempted(false);
        onClose();
    };

    const confirm = async () => {
        if (!code.trim()) return;
        setBusy(true);
        setError('');
        try {
            const response = await api.post('security/two-factor/confirm', { code: code.trim() });
            if (response.data.token) localStorage.setItem(Tokens.ADMIN, response.data.token);
            setRecoveryCodes(response.data.recovery_codes || []);
            setSetup(null);
            setCode('');
            onStatusChange({ enabled: true, confirmed_at: new Date().toISOString() });
            setNotice({ text: 'Autenticación de dos factores activada correctamente.' });
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'El código de autenticación no es válido.');
        } finally {
            setBusy(false);
        }
    };

    const disable = async () => {
        setBusy(true);
        setError('');
        try {
            await api.delete('security/two-factor', disableData);
            setDisableData(initialDisableData);
            setSetupAttempted(false);
            onStatusChange({ enabled: false, confirmed_at: null });
            setNotice({ text: 'Autenticación de dos factores desactivada. Deberás configurarla nuevamente para continuar.' });
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'No se pudo desactivar la protección.');
        } finally {
            setBusy(false);
        }
    };

    const copySecret = async () => {
        if (!setup?.secret) return;
        try {
            await navigator.clipboard.writeText(setup.secret);
            setNotice({ text: 'Clave secreta copiada.' });
        } catch (_) {
            setNotice({ type: 'error', text: 'No se pudo copiar automáticamente. Selecciona la clave y cópiala manualmente.' });
        }
    };

    if (!show) return null;

    const hasRecoveryCodes = recoveryCodes.length > 0;

    return <div className="sa-modal-backdrop sa-security-backdrop" role="presentation">
        <section className="sa-modal sa-two-factor-modal" role="dialog" aria-modal="true" aria-labelledby="sa-two-factor-title">
            <header>
                <div>
                    <span className="sa-eyebrow">SEGURIDAD DE LA CUENTA</span>
                    <h2 id="sa-two-factor-title">
                        {status?.enabled ? 'Autenticación de dos factores' : 'Habilitar la autenticación de dos factores'}
                    </h2>
                </div>
                {!required && <button type="button" onClick={close} aria-label="Cerrar">
                    <FontAwesomeIcon icon={faXmark} />
                </button>}
            </header>

            <div className="sa-modal-body">
                {required && !status?.enabled && <div className="sa-security-required">
                    <FontAwesomeIcon icon={faShieldHalved} />
                    <div>
                        <strong>Protección obligatoria para superadministradores</strong>
                        <span>Completa esta configuración antes de acceder a los datos de la plataforma.</span>
                    </div>
                </div>}

                {error && <div className="sa-form-error">
                    <FontAwesomeIcon icon={faTriangleExclamation} /> {error}
                </div>}

                {hasRecoveryCodes ? <div className="sa-recovery-complete">
                    <FontAwesomeIcon icon={faCheckCircle} />
                    <h3>Protección activada</h3>
                    <p>Guarda estos códigos en un lugar seguro. Cada uno funciona una sola vez y no volverán a mostrarse.</p>
                    <div className="sa-recovery-codes">
                        <div>{recoveryCodes.map(item => <code key={item}>{item}</code>)}</div>
                    </div>
                </div> : status?.enabled ? <>
                    <div className="sa-backup-note sa-backup-note--success">
                        <FontAwesomeIcon icon={faShieldHalved} />
                        <div>
                            <strong>Protección activa</strong>
                            <span>Tu cuenta solicitará un código temporal en cada inicio de sesión.</span>
                        </div>
                    </div>
                    <div className="sa-security-disable-block">
                        <h3>Desactivar autenticación</h3>
                        <p>Confirma tu contraseña y un código vigente. Por seguridad, el acceso volverá a exigir la configuración de 2FA.</p>
                        <div className="sa-security-disable">
                            <input type="password" autoComplete="current-password" placeholder="Contraseña actual"
                                value={disableData.password}
                                onChange={event => setDisableData({ ...disableData, password: event.target.value })} />
                            <input type="text" inputMode="numeric" autoComplete="one-time-code" placeholder="Código de autenticación"
                                value={disableData.code}
                                onChange={event => setDisableData({ ...disableData, code: event.target.value })} />
                            <button type="button" className="sa-btn sa-btn--reject" disabled={busy || !disableData.password || !disableData.code} onClick={disable}>
                                Desactivar 2FA
                            </button>
                        </div>
                    </div>
                </> : setup ? <>
                    <ol className="sa-security-steps">
                        <li><strong>Instala una aplicación de autenticación</strong><span>Google Authenticator, Authy u otra compatible con códigos TOTP.</span></li>
                        <li><strong>Escanea el código QR</strong><span>También puedes ingresar manualmente la clave secreta.</span></li>
                        <li><strong>Verifica el código</strong><span>Escribe el código de 6 dígitos que aparece en tu aplicación.</span></li>
                    </ol>
                    <div className="sa-two-factor-setup">
                        <img src={setup.qr_data_url} alt="Código QR para configurar autenticación" />
                        <div>
                            <span className="sa-security-label"><FontAwesomeIcon icon={faKey} /> Clave secreta</span>
                            <div className="sa-secret-row">
                                <code>{setup.secret}</code>
                                <button type="button" onClick={copySecret} title="Copiar clave"><FontAwesomeIcon icon={faClipboard} /></button>
                            </div>
                            <label className="sa-security-label" htmlFor="sa-two-factor-code">
                                <FontAwesomeIcon icon={faMobileScreenButton} /> Código de verificación
                            </label>
                            <div className="sa-security-confirm">
                                <input id="sa-two-factor-code" type="text" inputMode="numeric" autoComplete="one-time-code"
                                    maxLength="6" placeholder="000000" value={code}
                                    onChange={event => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))} />
                                <button type="button" className="sa-btn sa-btn--primary" disabled={busy || code.length !== 6} onClick={confirm}>
                                    {busy ? 'Verificando…' : 'Verificar y activar 2FA'}
                                </button>
                            </div>
                        </div>
                    </div>
                </> : busy ? <div className="sa-loading"><span /> Preparando configuración segura…</div> : <div className="sa-security-retry">
                    <p>No fue posible preparar el código QR.</p>
                    <button type="button" className="sa-btn sa-btn--primary" onClick={startSetup}>Intentar nuevamente</button>
                </div>}
            </div>

            {hasRecoveryCodes && <footer>
                <button type="button" className="sa-btn sa-btn--primary" onClick={() => {
                    setRecoveryCodes([]);
                    if (required) onClose(); else close();
                }}>He guardado mis códigos</button>
            </footer>}
        </section>
    </div>;
}
