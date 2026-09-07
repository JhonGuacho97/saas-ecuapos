import React, { useEffect, useState } from "react";
import { Modal } from "react-bootstrap";
import apiConfig from "../../config/apiConfig";
import { downloadAuthenticatedFile } from "../../shared/downloadAuthenticatedFile";

const ESTADO_BADGE = {
    completado: { texto: "Completado", color: "#28a745" },
    en_proceso: { texto: "Enviando", color: "#5bc0de" },
    pendiente: { texto: "Pendiente", color: "#adb5bd" },
    error: { texto: "Error", color: "#dc3545" },
    sin_enviar: { texto: "Sin enviar", color: "#adb5bd" },
};

const IconoPaso = ({ estado }) => {
    const color = ESTADO_BADGE[estado]?.color || "#adb5bd";

    if (estado === "completado") {
        return (
            <div style={{ width: 22, height: 22, borderRadius: "50%", background: color, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 13, flexShrink: 0 }}>
                ✓
            </div>
        );
    }
    if (estado === "error") {
        return (
            <div style={{ width: 22, height: 22, borderRadius: "50%", background: color, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 13, flexShrink: 0 }}>
                !
            </div>
        );
    }
    if (estado === "en_proceso") {
        return (
            <div style={{ width: 22, height: 22, borderRadius: "50%", border: `2px solid ${color}`, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
                <span className="spinner-border spinner-border-sm" style={{ width: 10, height: 10, color }} />
            </div>
        );
    }
    return (
        <div style={{ width: 22, height: 22, borderRadius: "50%", border: `2px solid ${color}`, flexShrink: 0 }} />
    );
};

const formatearHora = (iso) => {
    if (!iso) return null;
    const fecha = new Date(iso);
    return fecha.toLocaleDateString("es-EC", { day: "numeric", month: "short", year: "numeric" }) +
        ", " + fecha.toLocaleTimeString("es-EC", { hour: "numeric", minute: "2-digit" });
};

const RESUMEN_ESTADO = {
    PENDIENTE: "La emisión del comprobante está en proceso.",
    RECIBIDA: "La emisión del comprobante está en proceso.",
    AUTORIZADA: "El comprobante fue autorizado por el SRI.",
    NO_AUTORIZADA: "El SRI rechazó este comprobante.",
    DEVUELTA: "El SRI devolvió este comprobante.",
    ERROR_TEMPORAL_SRI: "El SRI tuvo un problema temporal -- no es un error de tu comprobante.",
};

const RutaEmisionPanel = ({ electronicInvoiceId, show, onHide, onReintentar }) => {
    const [datos, setDatos] = useState(null);
    const [cargando, setCargando] = useState(false);

    useEffect(() => {
        if (show && electronicInvoiceId) {
            setCargando(true);
            apiConfig
                .get(`/electronic-invoices/${electronicInvoiceId}/ruta`)
                .then((res) => setDatos(res.data.data))
                .finally(() => setCargando(false));
        }
        if (!show) {
            setDatos(null);
        }
    }, [show, electronicInvoiceId]);

    return (
        <Modal show={show} onHide={onHide} centered scrollable>
            <Modal.Header closeButton className="border-bottom">
                <Modal.Title style={{ fontSize: "1rem" }}>
                    {datos ? `${datos.tipo_comprobante === "04" ? "Nota de crédito" : (datos.tipo_comprobante === "05" ? "Nota de débito" : "Factura")} ${datos.numero_comprobante}` : "Ruta de emisión"}
                </Modal.Title>
            </Modal.Header>
            <Modal.Body style={{ maxHeight: "75vh" }}>
                {cargando && <p className="text-muted small">Cargando...</p>}

                {datos && (
                    <>
                        <p className="text-muted small mb-3">Ruta de emisión del comprobante.</p>

                        {RESUMEN_ESTADO[datos.estado] && (
                            <div className="alert alert-light border small py-2 mb-3">
                                {RESUMEN_ESTADO[datos.estado]}
                            </div>
                        )}

                        <div className="mb-4">
                            <div className="text-muted small mb-1">Clave de acceso</div>
                            <code className="d-block small" style={{ wordBreak: "break-all", background: "#f8f9fa", padding: "0.4rem 0.6rem", borderRadius: "0.4rem" }}>
                                {datos.clave_acceso}
                            </code>
                        </div>

                        <h6 className="mb-1">Ruta del comprobante</h6>
                        <p className="text-muted small mb-3">
                            Cada punto muestra el estado actual y el error cuando existe.
                        </p>

                        <div>
                            {datos.pasos.map((paso, i) => (
                                <div key={paso.clave} className="d-flex" style={{ gap: "0.75rem" }}>
                                    <div className="d-flex flex-column align-items-center">
                                        <IconoPaso estado={paso.estado} />
                                        {i < datos.pasos.length - 1 && (
                                            <div style={{ width: 2, flexGrow: 1, background: "#e9ecef", minHeight: 28 }} />
                                        )}
                                    </div>
                                    <div className="pb-3 flex-grow-1">
                                        <div className="d-flex justify-content-between align-items-start">
                                            <strong className="small">{paso.titulo}</strong>
                                            <span
                                                className="small fw-semibold"
                                                style={{ color: ESTADO_BADGE[paso.estado]?.color }}
                                            >
                                                {ESTADO_BADGE[paso.estado]?.texto}
                                            </span>
                                        </div>
                                        <div className="text-muted small">{paso.descripcion}</div>
                                        {paso.timestamp && (
                                            <div className="text-muted small mt-1">
                                                {formatearHora(paso.timestamp)}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <hr />

                        {datos.tiene_xml && (
                            <p className="text-muted small">XML firmado disponible</p>
                        )}

                        <div className="d-flex flex-column gap-2">
                            {datos.puede_ver_pdf && (
                                <button
                                    type="button"
                                    className="btn btn-outline-secondary btn-sm"
                                    onClick={() => downloadAuthenticatedFile(
                                        `/api/electronic-invoices/${electronicInvoiceId}/ride`,
                                        { open: true }
                                    )}
                                >
                                    📄 Ver PDF
                                </button>
                            )}
                            {datos.tiene_xml && (
                                <button
                                    type="button"
                                    className="btn btn-outline-secondary btn-sm"
                                    onClick={() => downloadAuthenticatedFile(
                                        `/api/electronic-invoices/${electronicInvoiceId}/xml`,
                                        { filename: `comprobante-${electronicInvoiceId}.xml` }
                                    )}
                                >
                                    ⬇ Descargar XML
                                </button>
                            )}
                            {datos.puede_reintentar && (
                                <button
                                    type="button"
                                    className="btn btn-outline-danger btn-sm"
                                    onClick={() => {
                                        onReintentar?.();
                                        onHide();
                                    }}
                                >
                                    ↻ Reintentar emisión
                                </button>
                            )}
                        </div>
                    </>
                )}
            </Modal.Body>
        </Modal>
    );
};

export default RutaEmisionPanel;
