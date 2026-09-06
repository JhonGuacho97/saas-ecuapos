import React, { useEffect } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faFileImage, faXmark } from '@fortawesome/free-solid-svg-icons';

export default function PaymentProofModal({ payment, imageUrl, loading, onClose }) {
    useEffect(() => {
        const closeWithEscape = event => event.key === 'Escape' && onClose();
        document.addEventListener('keydown', closeWithEscape);
        return () => document.removeEventListener('keydown', closeWithEscape);
    }, [onClose]);

    return <div
        className="sa-modal-backdrop"
        role="presentation"
        onMouseDown={event => event.target === event.currentTarget && onClose()}
    >
        <section className="sa-modal sa-proof-modal" role="dialog" aria-modal="true" aria-labelledby="payment-proof-title">
            <header>
                <div>
                    <span className="sa-eyebrow">RESPALDO DEL PAGO</span>
                    <h2 id="payment-proof-title">Comprobante de {payment.organization?.name || `pago #${payment.id}`}</h2>
                </div>
                <button type="button" onClick={onClose} aria-label="Cerrar comprobante">
                    <FontAwesomeIcon icon={faXmark} />
                </button>
            </header>
            <div className="sa-modal-body sa-proof-modal__body">
                {loading && <div className="sa-loading"><span /> Cargando comprobante…</div>}
                {!loading && imageUrl && <img src={imageUrl} alt={`Comprobante del pago #${payment.id}`} />}
                {!loading && !imageUrl && <div className="sa-empty">
                    <span className="sa-icon sa-icon--blue"><FontAwesomeIcon icon={faFileImage} /></span>
                    <p>No fue posible mostrar el comprobante.</p>
                </div>}
            </div>
            <footer>
                <button type="button" className="sa-btn sa-btn--soft" onClick={onClose}>Cerrar</button>
            </footer>
        </section>
    </div>;
}
