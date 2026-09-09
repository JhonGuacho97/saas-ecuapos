import React, { useState } from "react";

const reasons = [
    ["Pago", "Pago pendiente", "BILLING"],
    ["Administración", "Revisión o incumplimiento administrativo", "ADMINISTRATIVE"],
    ["Seguridad", "Actividad o acceso que requiere revisión", "SECURITY"],
];

export default function OrganizationSuspensionModal({ organization, onClose, onConfirm }) {
    const [reason, setReason] = useState("BILLING");
    const [note, setNote] = useState("");
    const [saving, setSaving] = useState(false);

    const submit = async (event) => {
        event.preventDefault();
        setSaving(true);
        try {
            await onConfirm({ suspension_reason: reason, suspension_note: note.trim() || null });
        } finally {
            setSaving(false);
        }
    };

    return <div className="sa-modal-backdrop" role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose()}>
        <section className="sa-modal" role="dialog" aria-modal="true" aria-labelledby="suspend-organization-title">
            <header>
                <div><span className="sa-eyebrow">CONTROL DE ACCESO</span><h2 id="suspend-organization-title">Suspender {organization.name}</h2></div>
                <button type="button" onClick={onClose} aria-label="Cerrar">×</button>
            </header>
            <form onSubmit={submit}>
                <div className="sa-modal-body">
                    <div className="sa-form-grid">
                        <label className="sa-field sa-field--full"><span>Motivo</span>
                            <select value={reason} onChange={event => setReason(event.target.value)}>
                                {reasons.map(([label, description, value]) => <option key={value} value={value}>{label} — {description}</option>)}
                            </select>
                        </label>
                        <label className="sa-field sa-field--full"><span>Nota interna (opcional)</span>
                            <textarea rows="4" maxLength="1000" value={note} onChange={event => setNote(event.target.value)} placeholder="Contexto para el equipo de soporte…" />
                        </label>
                    </div>
                    <p className="sa-form-hint">Solo una suspensión por pago puede reactivarse al aprobar un comprobante. Los bloqueos administrativos o de seguridad requieren activación manual.</p>
                </div>
                <footer>
                    <button type="button" className="sa-btn sa-btn--soft" onClick={onClose} disabled={saving}>Volver</button>
                    <button type="submit" className="sa-btn sa-btn--danger" disabled={saving}>{saving ? "Suspendiendo…" : "Confirmar suspensión"}</button>
                </footer>
            </form>
        </section>
    </div>;
}
