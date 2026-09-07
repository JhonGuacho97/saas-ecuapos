import React, { useState } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { Tokens } from '../../constants';
import SuperAdminLayout from './layout/SuperAdminLayout';
import LandingPageManager from './landing/LandingPageManager';
import PlatformSettings from './settings/PlatformSettings';
import {
    Dashboard,
    Organizations,
    Payments,
    Plans,
    Subscriptions,
    Users,
} from './pages/PlatformPages';
import './styles/super-admin.scss';

export default function SuperAdminApp() {
    const isSuperAdmin = localStorage.getItem(Tokens.IS_SUPER_ADMIN) === 'true';
    const [notice, setNotice] = useState(null);

    if (!isSuperAdmin) return <Navigate replace to="/app/dashboard" />;

    return <SuperAdminLayout notice={notice} setNotice={setNotice}>
        <Routes>
            <Route path="dashboard" element={<Dashboard />} />
            <Route path="organizations" element={<Organizations setNotice={setNotice} />} />
            <Route path="users" element={<Users />} />
            <Route path="plans" element={<Plans setNotice={setNotice} />} />
            <Route path="subscriptions" element={<Subscriptions setNotice={setNotice} />} />
            <Route path="payments" element={<Payments setNotice={setNotice} />} />
            <Route path="landing" element={<LandingPageManager setNotice={setNotice} />} />
            <Route path="settings" element={<PlatformSettings setNotice={setNotice} />} />
            <Route path="*" element={<Navigate replace to="dashboard" />} />
        </Routes>
    </SuperAdminLayout>;
}
