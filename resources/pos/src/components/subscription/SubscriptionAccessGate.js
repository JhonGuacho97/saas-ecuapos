import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faBuilding, faClock, faEnvelope, faReceipt, faRightFromBracket,
    faShieldHalved, faUserShield,
} from '@fortawesome/free-solid-svg-icons';
import apiConfig from '../../config/apiConfig';
import { Tokens } from '../../constants';
import PaymentCheckout from './PaymentCheckout';
import PlanCards from './PlanCards';
import { money } from './subscriptionHelpers';
import './subscription-access-gate.scss';

export default function SubscriptionAccessGate({ access, onRefresh }) {
    const [selectedPlan, setSelectedPlan] = useState(null);

    const logout = async () => {
        try { await apiConfig.post('logout'); } catch (_) {}
        Object.values(Tokens).forEach(key => localStorage.removeItem(key));
        localStorage.removeItem('loginUserArray');
        window.location.hash = '/login';
    };

    if (access.reason === 'OFFLINE_LEASE_EXPIRED' || access.reason === 'ACCESS_UNAVAILABLE') {
        return <GateShell logout={logout}>
            <section className="sub-state">
                <span className="sub-state-icon"><FontAwesomeIcon icon={faShieldHalved} /></span>
                <span className="sub-kicker">VALIDACIÓN DE SEGURIDAD</span>
                <h1>Conéctate para validar tu suscripción</h1>
                <p>No pudimos confirmar el estado de esta cuenta. Cuando recuperes Internet, vuelve a intentarlo; tus datos locales permanecen protegidos.</p>
                <button className="sub-btn sub-btn--primary" onClick={onRefresh}>Volver a comprobar</button>
            </section>
        </GateShell>;
    }

    // Un cajero no contrata ni renueva y tampoco necesita ver referencias
    // o montos de un comprobante enviado por el administrador.
    if (access.can_manage === false) {
        return <GateShell logout={logout}>
            <section className="sub-state">
                <span className="sub-state-icon"><FontAwesomeIcon icon={faUserShield} /></span>
                <span className="sub-kicker">SE REQUIERE UN ADMINISTRADOR</span>
                <h1>La suscripción de {access.organization?.name} necesita atención</h1>
                <p>Tu cuenta sigue activa y tu información está intacta, pero solo un administrador de la organización puede renovar o mejorar el plan. Pídele que ingrese a EcuaPos y abra <strong>Administrar suscripciones</strong> desde su menú de usuario.</p>
                <button className="sub-btn sub-btn--soft" onClick={onRefresh}>Ya renovaron, actualizar</button>
                <small>¿Necesitas ayuda? Escríbenos a support@ecua-pos.com</small>
            </section>
        </GateShell>;
    }

    if (access.pending_payment) {
        return <GateShell logout={logout}>
            <section className="sub-state sub-state--pending">
                <span className="sub-state-icon"><FontAwesomeIcon icon={faClock} /></span>
                <span className="sub-kicker">COMPROBANTE RECIBIDO</span>
                <h1>Tu pago está en revisión</h1>
                <p>Validaremos el comprobante y activaremos <strong>{access.pending_payment.plan?.name}</strong>. Tus datos permanecen seguros durante el proceso.</p>
                <div className="sub-pending-summary">
                    <div><span>Organización</span><strong>{access.organization?.name}</strong></div>
                    <div><span>Monto enviado</span><strong>{money(access.pending_payment.amount, access.pending_payment.currency)}</strong></div>
                    <div><span>Referencia</span><strong>{access.pending_payment.provider_reference}</strong></div>
                </div>
                <button className="sub-btn sub-btn--soft" onClick={onRefresh}>Actualizar estado</button>
                <small>Normalmente revisamos los pagos manuales en horario de atención.</small>
            </section>
        </GateShell>;
    }

    if (selectedPlan) {
        return <GateShell logout={logout}>
            <PaymentCheckout
                plan={selectedPlan}
                onBack={() => setSelectedPlan(null)}
                onSubmitted={async () => { setSelectedPlan(null); await onRefresh(); }}
            />
        </GateShell>;
    }

    const inactive = access.reason === 'ORGANIZATION_INACTIVE';
    const administrativeSuspension = inactive && access.can_purchase === false;
    return <GateShell logout={logout}>
        <section className="sub-hero">
            <span className="sub-state-icon"><FontAwesomeIcon icon={inactive ? faBuilding : faReceipt} /></span>
            {/* Este muro ya no cubre el vencimiento con la organización
                activa -- eso ahora abre la app en modo consulta (ver
                App.js y access_mode). Queda para la suspensión y como
                respaldo, así que el texto no puede dar por hecho que lo
                que venció fue una prueba: también puede ser un plan
                pagado. */}
            <span className="sub-kicker">{inactive ? 'ACCESO TEMPORALMENTE SUSPENDIDO' : 'TU SUSCRIPCIÓN FINALIZÓ'}</span>
            <h1>{inactive ? 'Esta organización no está disponible' : 'Continúa trabajando con EcuaPos'}</h1>
            <p>{administrativeSuspension
                ? 'El acceso fue suspendido por una revisión administrativa o de seguridad. Un pago no puede reactivar esta cuenta; contacta con soporte para resolver el bloqueo.'
                : inactive
                    ? 'El acceso fue suspendido por un pago pendiente. Toda tu información continúa protegida y podrás recuperarla al regularizar tu plan.'
                    : 'Tus productos, ventas y configuraciones siguen guardados. Elige el plan que mejor se adapte a tu negocio para recuperar el acceso.'}</p>
            {inactive && <div className="sub-inline-alert"><FontAwesomeIcon icon={faShieldHalved} /> Ningún dato ha sido eliminado.</div>}
            {administrativeSuspension && <a className="sub-btn sub-btn--primary" href="mailto:support@ecua-pos.com">Contactar con soporte</a>}
        </section>
        {!administrativeSuspension && <PlanCards plans={access.plans} onSelect={setSelectedPlan} />}
    </GateShell>;
}

function GateShell({ children, logout }) {
    return <div className="sub-shell"><header><div className="sub-brand"><img src="/images/ecua-pos-logo.png" alt="EcuaPos" /><div><strong>EcuaPos</strong><small>Tu negocio, siempre organizado</small></div></div><div className="sub-secure"><FontAwesomeIcon icon={faShieldHalved} /> Pago seguro</div></header><main>{children}</main><footer><span>© {new Date().getFullYear()} EcuaPosSoft</span><a href="mailto:support@ecua-pos.com"><FontAwesomeIcon icon={faEnvelope} /> Soporte</a><button onClick={logout}><FontAwesomeIcon icon={faRightFromBracket} /> Cerrar sesión</button></footer></div>;
}
