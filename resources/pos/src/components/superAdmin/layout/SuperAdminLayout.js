import React, { useCallback } from 'react';
import { NavLink, useLocation, useNavigate } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faRightFromBracket } from '@fortawesome/free-solid-svg-icons';
import apiConfig from '../../../config/apiConfig';
import { Tokens } from '../../../constants';
import { getSuperAdminPageTitle, SUPER_ADMIN_BASE_PATH, superAdminNavigation } from '../config/navigation';
import SuperAdminNotice from './SuperAdminNotice';

export default function SuperAdminLayout({ children, notice, setNotice }) {
    const navigate = useNavigate();
    const location = useLocation();
    const user = JSON.parse(localStorage.getItem('loginUserArray') || '{}');
    const title = getSuperAdminPageTitle(location.pathname);
    const closeNotice = useCallback(() => setNotice(null), [setNotice]);

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
            <div className="sa-sidebar-foot">
                <div className="sa-avatar">{(user.first_name || 'S').charAt(0)}</div>
                <div><strong>{user.first_name || 'Super Admin'}</strong><small>Control global</small></div>
                <button type="button" onClick={logout} title="Cerrar sesión" aria-label="Cerrar sesión">
                    <FontAwesomeIcon icon={faRightFromBracket} />
                </button>
            </div>
        </aside>
        <main className="sa-main">
            <header className="sa-topbar">
                <div><small>Panel de superadministración</small><h1>{title}</h1></div>
                <div className="sa-topbar-badge"><span /> Operación en línea</div>
            </header>
            <SuperAdminNotice notice={notice} onClose={closeNotice} />
            <div className="sa-content">{children}</div>
        </main>
    </div>;
}
