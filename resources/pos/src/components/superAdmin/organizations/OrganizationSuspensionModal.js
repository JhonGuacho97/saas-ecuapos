import React, { useState } from "react";

const reasons = [
    ["Pago pendiente", "Se podrá reactivar al aprobar el comprobante.", "BILLING"],
    ["Administración", "Requiere una reactivación manual del equipo.", "ADMINISTRATIVE"],
    ["Seguridad", "Bloquea el acceso hasta finalizar la revisión.", "SECURITY"],
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
        <section className="sa-modal sa-suspension-modal" role="dialog" aria-modal="true" aria-labelledby="suspend-organization-title">
            <header>
                <div>
                    <span className="sa-eyebrow">CONTROL DE ACCESO</span>
                    <h2 id="suspend-organization-title">Suspender {organization.name}</h2>
                    <p>La organización perderá el acceso operativo hasta que vuelva a activarse.</p>
                </div>
                <button type="button" onClick={onClose} aria-label="Cerrar">×</button>
            </header>
            <form className="sa-suspension-form" onSubmit={submit}>
                <div className="sa-modal-body">
                    <fieldset className="sa-suspension-reasons">
                        <legend>Selecciona el motivo</legend>
                        <div>
                            {reasons.map(([label, description, value]) => (
                                <label key={value} className={reason === value ? "is-selected" : ""}>
                                    <input
                                        type="radio"
                                        name="suspension_reason"
                                        value={value}
                                        checked={reason === value}
                                        onChange={event => setReason(event.target.value)}
                                    />
                                    <span><strong>{label}</strong><small>{description}</small></span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <label className="sa-field sa-suspension-note">
                        <span>Nota interna <small>Opcional</small></span>
                        <textarea rows="4" maxLength="1000" value={note} onChange={event => setNote(event.target.value)} placeholder="Agrega contexto para el equipo de soporte…" />
                    </label>
                    <div className="sa-suspension-hint" role="note">
                        <strong>Importante</strong>
                        <span>Solo las suspensiones por pago pueden reactivarse automáticamente al aprobar un comprobante.</span>
                    </div>
                </div>
                <footer>
                    <button type="button" className="sa-btn sa-btn--soft" onClick={onClose} disabled={saving}>Cancelar</button>
                    <button type="submit" className="sa-btn sa-btn--danger" disabled={saving}>{saving ? "Suspendiendo…" : "Confirmar suspensión"}</button>
                </footer>
            </form>
        </section>
    </div>;
}
