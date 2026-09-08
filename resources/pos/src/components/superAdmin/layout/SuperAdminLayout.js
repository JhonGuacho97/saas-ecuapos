import React, { useCallback, useEffect, useRef, useState } from 'react';
import { NavLink, useLocation, useNavigate } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faAngleUp, faRightFromBracket, faShieldHalved, faUser } from '@fortawesome/free-solid-svg-icons';
import apiConfig from '../../../config/apiConfig';
import { Tokens } from '../../../constants';
import { getSuperAdminPageTitle, SUPER_ADMIN_BASE_PATH, superAdminNavigation } from '../config/navigation';
import SuperAdminNotice from './SuperAdminNotice';
import TwoFactorModal from '../security/TwoFactorModal';
import api from '../api/superAdminApi';

export default function SuperAdminLayout({ children, notice, setNotice }) {
    const navigate = useNavigate();
    const location = useLocation();
    const user = JSON.parse(localStorage.getItem('loginUserArray') || '{}');
    const title = getSuperAdminPageTitle(location.pathname);
    const closeNotice = useCallback(() => setNotice(null), [setNotice]);
    const profileRef = useRef(null);
    const [profileOpen, setProfileOpen] = useState(false);
    const [securityStatus, setSecurityStatus] = useState(null);
    const [securityError, setSecurityError] = useState('');
    const [securityModalOpen, setSecurityModalOpen] = useState(false);

    const loadSecurityStatus = useCallback(() => {
        setSecurityError('');
        api.get('security/two-factor')
            .then(status => {
                setSecurityStatus(status);
                if (!status.enabled) setSecurityModalOpen(true);
            })
            .catch(error => setSecurityError(error.response?.data?.message || 'No se pudo verificar la seguridad de la cuenta.'));
    }, []);

    useEffect(loadSecurityStatus, [loadSecurityStatus]);

    useEffect(() => {
        const closeProfile = event => {
            if (profileRef.current && !profileRef.current.contains(event.target)) setProfileOpen(false);
        };
        document.addEventListener('mousedown', closeProfile);
        return () => document.removeEventListener('mousedown', closeProfile);
    }, []);

    const logout = async () => {
        try { await apiConfig.post('logout'); } catch (_) {}
        Object.values(Tokens).forEach(key => localStorage.removeItem(key));
        localStorage.removeItem('loginUserArray');
        navigate('/login', { replace: true });
    };

    return <div className="sa-shell">
        <aside className="sa-sidebar">
            <div className="sa-brand">
                <img src="/images/ecua-pos-logo.png" alt="EcuaPos" />
                <div><strong>EcuaPos</strong><small>Administración SaaS</small></div>
            </div>
            <div className="sa-sidebar-label">PLATAFORMA</div>
            <nav aria-label="Navegación de superadministración">
                {superAdminNavigation.map(item => <NavLink
                    key={item.key}
                    to={`${SUPER_ADMIN_BASE_PATH}/${item.key}`}
                    className={({ isActive }) => isActive ? 'active' : ''}
                >
                    <FontAwesomeIcon icon={item.icon} />
                    <span>{item.label}</span>
                </NavLink>)}
            </nav>
            <div className="sa-profile" ref={profileRef}>
                {profileOpen && <div className="sa-profile-menu">
                    <div><strong>{user.first_name || 'Super Admin'} {user.last_name || ''}</strong><small>{user.email || 'Control global'}</small></div>
                    <button type="button" onClick={() => { setSecurityModalOpen(true); setProfileOpen(false); }}>
                        <FontAwesomeIcon icon={faShieldHalved} /> Autenticación de dos factores
                    </button>
                    <button type="button" onClick={logout} className="is-logout">
                        <FontAwesomeIcon icon={faRightFromBracket} /> Cerrar sesión
                    </button>
                </div>}
                <button type="button" className="sa-sidebar-foot" onClick={() => setProfileOpen(value => !value)} aria-expanded={profileOpen}>
                    <div className="sa-avatar">{(user.first_name || 'S').charAt(0)}</div>
                    <div><strong>{user.first_name || 'Super Admin'}</strong><small>Perfil y seguridad</small></div>
                    <FontAwesomeIcon icon={profileOpen ? faAngleUp : faUser} />
                </button>
            </div>
        </aside>
        <main className="sa-main">
            <header className="sa-topbar">
                <div><small>Panel de superadministración</small><h1>{title}</h1></div>
                <div className="sa-topbar-badge"><span /> Operación en línea</div>
            </header>
            <SuperAdminNotice notice={notice} onClose={closeNotice} />
            <div className="sa-content">
                {securityStatus?.enabled ? children : securityError ? <div className="sa-card sa-security-gate-error">
                    <FontAwesomeIcon icon={faShieldHalved} />
                    <h2>No pudimos verificar la seguridad de tu cuenta</h2>
                    <p>{securityError}</p>
                    <div>
                        <button type="button" className="sa-btn sa-btn--primary" onClick={loadSecurityStatus}>Intentar nuevamente</button>
                        <button type="button" className="sa-btn sa-btn--soft" onClick={logout}>Cerrar sesión</button>
                    </div>
                </div> : <div className="sa-loading sa-security-gate"><span /> Verificando acceso seguro…</div>}
            </div>
        </main>
        <TwoFactorModal
            show={securityModalOpen}
            required={securityStatus !== null && !securityStatus.enabled}
            status={securityStatus}
            onClose={() => setSecurityModalOpen(false)}
            onStatusChange={status => {
                setSecurityStatus(status);
                if (!status.enabled) setSecurityModalOpen(true);
            }}
            setNotice={setNotice}
        />
    </div>;
}
