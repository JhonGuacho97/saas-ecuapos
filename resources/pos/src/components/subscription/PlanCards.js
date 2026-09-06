import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheck, faEnvelope } from '@fortawesome/free-solid-svg-icons';
import { interval, money, planFeatures, shortDate } from './subscriptionHelpers';
import './subscription-access-gate.scss';

/**
 * Catálogo de planes. Lo comparten el muro de acceso (cuenta vencida) y
 * la pantalla de administración dentro de la app, para que renovar y
 * mejorar se vean y se comporten igual en los dos lugares.
 */
export default function PlanCards({ plans, currentPlanId, renewal, onSelect, disabled, disabledLabel }) {
    if (!plans?.length) {
        return <div className="sub-no-plans">
            <FontAwesomeIcon icon={faEnvelope} />
            <strong>Estamos preparando los planes disponibles</strong>
            <a href="mailto:support@ecua-pos.com">Contactar a soporte</a>
        </div>;
    }

    // El plan de prueba no se lista, así que una cuenta en trial no tiene
    // ninguna tarjeta "actual": ahí se recomienda la primera.
    const currentIsListed = plans.some(plan => String(plan.id) === String(currentPlanId));

    return <section className="sub-plans">
        {plans.map((plan, index) => {
            const isCurrent = currentIsListed && String(plan.id) === String(currentPlanId);
            const renewalLocked = isCurrent && renewal?.can_renew_current_plan === false;
            const cardDisabled = Boolean(disabled || renewalLocked);
            const buttonLabel = disabled
                ? (disabledLabel || 'No disponible')
                : renewalLocked
                    ? `Disponible desde ${shortDate(renewal.available_at)}`
                    : isCurrent ? 'Renovar este plan' : 'Cambiar a este plan';
            return <article key={plan.id} className={isCurrent ? 'featured' : ''}>
                {isCurrent && <b className="sub-recommended">TU PLAN ACTUAL</b>}
                {!currentIsListed && index === 0 && <b className="sub-recommended">RECOMENDADO</b>}
                <span className="sub-kicker">PLAN ECUAPOS</span>
                <h2>{plan.name}</h2>
                <p>{plan.description || 'Todo lo necesario para operar tu negocio.'}</p>
                <div className="sub-plan-price">
                    <strong>{money(plan.price, plan.currency)}</strong><span>/ {interval(plan)}</span>
                </div>
                <ul>
                    {planFeatures(plan).map(feature => (
                        <li key={feature}><FontAwesomeIcon icon={faCheck} /> {feature}</li>
                    ))}
                </ul>
                <button
                    className="sub-btn sub-btn--primary"
                    disabled={cardDisabled}
                    onClick={() => onSelect(plan)}
                >
                    {buttonLabel}
                </button>
                {renewalLocked && <small className="sub-renewal-note">Podrás renovarlo durante los últimos {renewal.window_days || 5} días de vigencia.</small>}
            </article>;
        })}
    </section>;
}
