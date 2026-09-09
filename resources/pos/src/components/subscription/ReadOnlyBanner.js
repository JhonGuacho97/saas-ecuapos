import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faEye, faCreditCard, faXmark, faChevronUp } from '@fortawesome/free-solid-svg-icons';
import './read-only-banner.scss';

/**
 * Barra de modo consulta.
 *
 * Va fija abajo y no arriba a propósito: el encabezado y la barra lateral
 * del POS son fijos, y una franja superior se les monta encima o desplaza
 * todo el layout. Abajo convive con cualquier pantalla sin tocar el resto
 * de la app.
 *
 * Se puede minimizar pero no cerrar: mientras la suscripción esté vencida
 * el usuario tiene que poder ver por qué no le deja guardar. Al minimizar
 * queda una pastilla que reabre la barra.
 */
export default function ReadOnlyBanner({ canManage, organizationName }) {
    const [minimized, setMinimized] = useState(false);

    if (minimized) {
        return (
            <button className="ro-pill" onClick={() => setMinimized(false)} title="Modo consulta activo">
                <FontAwesomeIcon icon={faEye} /> Solo consulta
                <FontAwesomeIcon icon={faChevronUp} className="ro-pill-caret" />
            </button>
        );
    }

    return (
        <aside className="ro-bar" role="status">
            <span className="ro-bar-icon"><FontAwesomeIcon icon={faEye} /></span>
            <div className="ro-bar-text">
                <strong>Estás en modo consulta</strong>
                <span>
                    {organizationName ? `La suscripción de ${organizationName} venció. ` : 'La suscripción venció. '}
                    Puedes revisar y exportar toda tu información, pero no registrar ni modificar nada hasta renovar.
                </span>
            </div>
            <div className="ro-bar-actions">
                {canManage
                    ? <Link className="ro-bar-cta" to="/app/subscription">
                        <FontAwesomeIcon icon={faCreditCard} /> Renovar ahora
                    </Link>
                    : <span className="ro-bar-hint">Pídele a un administrador que renueve el plan.</span>}
                <button className="ro-bar-min" onClick={() => setMinimized(true)} title="Minimizar">
                    <FontAwesomeIcon icon={faXmark} />
                </button>
            </div>
        </aside>
    );
}
