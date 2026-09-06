import React, { useCallback, useEffect, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { useSelector } from 'react-redux';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faCircleCheck, faClock, faGaugeHigh, faReceipt, faRotate, faTriangleExclamation,
} from '@fortawesome/free-solid-svg-icons';
import MasterLayout from '../MasterLayout';
import TabTitle from '../../shared/tab-title/TabTitle';
import TopProgressBar from '../../shared/components/loaders/TopProgressBar';
import apiConfig from '../../config/apiConfig';
import PaymentCheckout from './PaymentCheckout';
import PlanCards from './PlanCards';
import { money, shortDate, statusLabel } from './subscriptionHelpers';
import './subscription-manager.scss';

const USAGE_LABELS = {
    users: 'Usuarios',
    stores: 'Tiendas',
    warehouses: 'Almacenes',
    electronic_documents: 'Documentos electrónicos',
};

/**
 * Administración de la suscripción desde dentro de la app: estado del
 * plan vigente, consumo contra los límites y renovación o mejora sin
 * tener que esperar a que la cuenta se bloquee.
 */
export default function SubscriptionManager() {
    const { allConfigData } = useSelector(state => state);
    const [portal, setPortal] = useState(null);
    const [selectedPlan, setSelectedPlan] = useState(null);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        try {
            const response = await apiConfig.get('subscription-portal');
            setPortal(response.data.data);
            setError('');
        } catch (requestError) {
            setError(requestError.response?.data?.message || 'No pudimos cargar la información de tu suscripción.');
            setPortal({ plans: [] });
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    // El menú ya oculta la opción, pero la ruta es escribible a mano.
    if (allConfigData?.can_manage_subscription === false) {
        return <Navigate replace to="/app/dashboard" />;
    }

    if (!portal) {
        return <MasterLayout><TopProgressBar isLoading /><TabTitle title="Suscripción" /></MasterLayout>;
    }

    if (portal.can_manage === false) {
        return <Navigate replace to="/app/dashboard" />;
    }

    const summary = portal.subscription || {};
    const pending = portal.pending_payment;
    const expired = summary.status === 'EXPIRED' || summary.status === 'MISSING';
    const renewalDate = summary.next_billing_at || summary.current_period_ends_at || summary.trial_ends_at;

    return (
        <MasterLayout>
            <TabTitle title="Suscripción" />
            <div className="sm-page">
                <header className="sm-head">
                    <div>
                        <span className="sub-kicker">TU CUENTA ECUAPOS</span>
                        <h1>Administrar suscripción</h1>
                        <p>Consulta el estado de tu plan, renuévalo antes de que venza o mejóralo cuando tu negocio crezca.</p>
                    </div>
                    <button className="sub-btn sub-btn--soft" onClick={load}>
                        <FontAwesomeIcon icon={faRotate} /> Actualizar
                    </button>
                </header>

                {error && <div className="sub-error sm-block">{error}</div>}

                <section className={`sm-status ${expired ? 'sm-status--danger' : ''}`}>
                    <div className="sm-status-main">
                        <span className="sm-status-icon">
                            <FontAwesomeIcon icon={expired ? faTriangleExclamation : faCircleCheck} />
                        </span>
                        <div>
                            <span className="sub-kicker">PLAN ACTUAL</span>
                            <h2>{summary.plan?.name || 'Sin plan asignado'}</h2>
                            <p>{portal.organization?.name}</p>
                        </div>
                    </div>
                    <dl className="sm-status-facts">
                        <div><dt>Estado</dt><dd>{statusLabel(summary.status)}</dd></div>
                        <div><dt>{summary.is_trial ? 'La prueba termina' : 'Próxima renovación'}</dt><dd>{shortDate(renewalDate)}</dd></div>
                        {summary.is_trial && <div><dt>Días restantes</dt><dd>{summary.days_remaining ?? 0}</dd></div>}
                        <div><dt>Renovación automática</dt><dd>{summary.auto_renew ? 'Activada' : 'Manual'}</dd></div>
                    </dl>
                </section>

                {pending && (
                    <section className="sm-pending">
                        <span className="sm-status-icon"><FontAwesomeIcon icon={faClock} /></span>
                        <div>
                            <strong>Tienes un comprobante en revisión</strong>
                            <span>
                                {pending.plan?.name} · {money(pending.amount, pending.currency)} · Ref. {pending.provider_reference}.
                                Activaremos el plan apenas verifiquemos el pago.
                            </span>
                        </div>
                    </section>
                )}

                {summary.limits && (
                    <section className="sm-usage">
                        <div className="sm-section-head">
                            <FontAwesomeIcon icon={faGaugeHigh} />
                            <h3>Consumo del plan</h3>
                        </div>
                        <div className="sm-usage-grid">
                            {Object.entries(USAGE_LABELS).map(([key, label]) => {
                                const limit = summary.limits?.[key];
                                const used = summary.usage?.[key] ?? 0;
                                const percent = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
                                return (
                                    <article key={key}>
                                        <span>{label}</span>
                                        <strong>{used}<small> / {limit || '∞'}</small></strong>
                                        <div className="sm-bar">
                                            <i className={percent >= 100 ? 'is-full' : ''} style={{ width: `${limit ? percent : 4}%` }} />
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                    </section>
                )}

                <section className="sm-plans">
                    <div className="sm-section-head">
                        <FontAwesomeIcon icon={faReceipt} />
                        <h3>{selectedPlan ? 'Solicitud de pago' : 'Renovar o mejorar tu plan'}</h3>
                    </div>
                    {selectedPlan ? (
                        <PaymentCheckout
                            plan={selectedPlan}
                            onBack={() => setSelectedPlan(null)}
                            onSubmitted={async () => { setSelectedPlan(null); await load(); }}
                        />
                    ) : (
                        <PlanCards
                            plans={portal.plans}
                            currentPlanId={portal.current_plan_id}
                            renewal={portal.renewal}
                            onSelect={setSelectedPlan}
                            disabled={Boolean(pending)}
                            disabledLabel="Comprobante en revisión"
                        />
                    )}
                </section>
            </div>
        </MasterLayout>
    );
}
