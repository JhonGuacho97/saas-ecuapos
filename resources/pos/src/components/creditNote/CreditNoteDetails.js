import React, { useEffect, useState } from "react";
import { useParams, Link } from "react-router-dom";
import SweetAlert from "react-bootstrap-sweetalert";
import apiConfig from "../../config/apiConfig";
import MasterLayout from "../MasterLayout";
import { downloadAuthenticatedFile } from "../../shared/downloadAuthenticatedFile";

const CONCEPTO_LABEL = {
    POR_DEVOLUCION: "Por Devolución (ajustó stock)",
    POR_DESCUENTO: "Por Descuento",
    POR_CORRECCION_PRECIO: "Por Corrección de Precio",
    POR_ERROR_FACTURACION: "Por Error de Facturación",
    OTRO: "Otro",
};

const GENERAR_COMO_LABEL = {
    SALDO: "Normal (Saldo)",
    ANTICIPO: "Anticipo del cliente",
};

const CreditNoteDetails = () => {
    const { id } = useParams();
    const [creditNote, setCreditNote] = useState(null);
    const [cargando, setCargando] = useState(true);
    const [emitiendo, setEmitiendo] = useState(false);
    const [mensajeEmision, setMensajeEmision] = useState(null);
    const [confirmandoCancelacion, setConfirmandoCancelacion] = useState(false);
    const [cancelando, setCancelando] = useState(false);

    const recargar = () => {
        apiConfig.get(`/credit-notes/${id}`).then((res) => setCreditNote(res.data.data));
    };

    const cancelarNota = () => {
        setCancelando(true);
        apiConfig
            .post(`/credit-notes/${id}/cancelar`)
            .then((res) => {
                setMensajeEmision({ tipo: 'success', texto: res.data.message });
                recargar();
            })
            .catch((error) => {
                setMensajeEmision({
                    tipo: 'danger',
                    texto: error?.response?.data?.message || 'No se pudo cancelar la nota de crédito.',
                });
            })
            .finally(() => {
                setCancelando(false);
                setConfirmandoCancelacion(false);
            });
    };

    const emitir = () => {
        setEmitiendo(true);
        setMensajeEmision(null);
        apiConfig
            .post(`/credit-notes/${id}/emitir`)
            .then((res) => {
                setMensajeEmision({ tipo: 'success', texto: res.data.message });
                setTimeout(recargar, 4000);
            })
            .catch((error) => {
                setMensajeEmision({
                    tipo: 'danger',
                    texto: error?.response?.data?.message || 'No se pudo iniciar la emisión.',
                });
            })
            .finally(() => setEmitiendo(false));
    };

    useEffect(() => {
        apiConfig
            .get(`/credit-notes/${id}`)
            .then((res) => setCreditNote(res.data.data))
            .finally(() => setCargando(false));
    }, [id]);

    if (cargando) {
        return (
            <MasterLayout>
                <p className="text-muted">Cargando...</p>
            </MasterLayout>
        );
    }

    if (!creditNote) {
        return (
            <MasterLayout>
                <div className="alert alert-danger">No se encontró la nota de crédito.</div>
            </MasterLayout>
        );
    }

    const items = creditNote.credit_note_items || [];
    const subtotal = items.reduce((acc, it) => acc + (it.sub_total || 0), 0);

    return (
        <MasterLayout>
            <div className="d-flex justify-content-between align-items-center mb-4">
                <h4 className="mb-0 d-flex align-items-center gap-2">
                    Nota de Crédito {creditNote.reference_code}
                    {creditNote.esta_cancelada && (
                        <span className="badge bg-secondary">Cancelada</span>
                    )}
                </h4>
                <div className="d-flex gap-2">
                    {!creditNote.electronic_invoice_estado && !creditNote.esta_cancelada && (
                        <button
                            className="btn btn-primary d-inline-flex align-items-center gap-2"
                            onClick={emitir}
                            disabled={emitiendo}
                        >
                            {emitiendo ? (
                                <>
                                    <span className="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
                                    Emitiendo...
                                </>
                            ) : (
                                <>
                                    <i className="bi bi-send" style={{ fontSize: 14 }} />
                                    Emitir nota de crédito
                                </>
                            )}
                        </button>
                    )}
                    {creditNote.puede_cancelarse && (
                        <button
                            className="btn btn-outline-danger d-inline-flex align-items-center gap-2"
                            onClick={() => setConfirmandoCancelacion(true)}
                            disabled={cancelando}
                        >
                            <i className="bi bi-x-circle" style={{ fontSize: 14 }} />
                            Cancelar nota
                        </button>
                    )}
                    <Link to="/app/credit-notes" className="btn btn-outline-primary">‹ Volver al listado</Link>
                </div>
            </div>

            {confirmandoCancelacion && (
                <SweetAlert
                    custom
                    confirmBtnBsStyle='danger mb-3 fs-5 rounded'
                    cancelBtnBsStyle='secondary mb-3 fs-5 rounded text-white'
                    confirmBtnText='Sí, cancelar nota'
                    cancelBtnText='Volver'
                    title='¿Cancelar esta nota de crédito?'
                    onConfirm={cancelarNota}
                    onCancel={() => setConfirmandoCancelacion(false)}
                    showCancel
                    focusCancelBtn
                    disabled={cancelando}
                >
                    <span className='sweet-text'>
                        {creditNote.concepto === 'POR_DEVOLUCION'
                            ? 'Esto revierte el stock que esta nota había devuelto al inventario y anula su efecto sobre el saldo disponible de la factura. Esta acción no se puede deshacer.'
                            : 'Esto anula el efecto de esta nota sobre el saldo disponible de la factura. Esta acción no se puede deshacer.'}
                    </span>
                </SweetAlert>
            )}

            {mensajeEmision && (() => {
                const iconMap = {
                    success: 'bi-check-circle',
                    danger: 'bi-x-circle',
                    warning: 'bi-exclamation-circle',
                    info: 'bi-info-circle',
                };

                return (
                    <div
                        className={`d-flex align-items-start gap-2 border rounded-3 p-3 mb-3 border-${mensajeEmision.tipo}-subtle bg-${mensajeEmision.tipo}-subtle`}
                    >
                        <i
                            className={`bi ${iconMap[mensajeEmision.tipo] || 'bi-info-circle'} text-${mensajeEmision.tipo} flex-shrink-0`}
                            style={{ fontSize: 16, marginTop: 2 }}
                        />
                        <p className={`mb-0 flex-grow-1 text-${mensajeEmision.tipo}-emphasis`} style={{ fontSize: 14 }}>
                            {mensajeEmision.texto}
                        </p>
                        <button
                            type="button"
                            className="btn-close"
                            style={{ fontSize: 11 }}
                            aria-label="Cerrar"
                            onClick={() => setMensajeEmision(null)}
                        />
                    </div>
                );
            })()}

            {creditNote.electronic_invoice_estado && (() => {
                const estado = creditNote.electronic_invoice_estado;
                const config = {
                    AUTORIZADA: { icon: 'bi-check-lg', color: 'success', label: 'Autorizada' },
                    'NO AUTORIZADA': { icon: 'bi-x-lg', color: 'danger', label: 'No autorizada' },
                    DEVUELTA: { icon: 'bi-x-lg', color: 'danger', label: 'Devuelta' },
                    PENDIENTE: { icon: 'bi-clock', color: 'warning', label: 'Pendiente' },
                    PROCESANDO: { icon: 'bi-clock', color: 'warning', label: 'En proceso' },
                }[estado] || { icon: 'bi-question-lg', color: 'secondary', label: estado };

                return (
                    <div className="border rounded-3 p-3 mb-2 bg-white">
                        <div className="d-flex align-items-start gap-3">
                            <div
                                className={`d-flex align-items-center justify-content-center flex-shrink-0 bg-${config.color}-subtle`}
                                style={{ width: 36, height: 36, borderRadius: '50%' }}
                            >
                                <i className={`bi ${config.icon} text-${config.color}`} style={{ fontSize: 16 }} />
                            </div>

                            <div className="flex-grow-1 min-w-0">
                                <p className="text-uppercase text-muted mb-1" style={{ fontSize: 12, letterSpacing: '0.03em' }}>
                                    Comprobante electrónico
                                </p>
                                <p className={`fw-medium mb-0 text-${config.color}`} style={{ fontSize: 16 }}>
                                    {config.label}
                                </p>

                                {creditNote.electronic_invoice_numero_autorizacion && (
                                    <div className="mt-2 pt-2 border-top">
                                        <p className="text-muted mb-1" style={{ fontSize: 12 }}>Número de autorización</p>
                                        <p className="mb-0 text-break" style={{ fontFamily: 'var(--font-mono, monospace)', fontSize: 13 }}>
                                            {creditNote.electronic_invoice_numero_autorizacion}
                                        </p>
                                    </div>
                                )}

                                {estado === 'AUTORIZADA' && creditNote.electronic_invoice_id && (
                                    <button
                                        type="button"
                                        onClick={() => downloadAuthenticatedFile(
                                            `/api/electronic-invoices/${creditNote.electronic_invoice_id}/ride`,
                                            { open: true }
                                        ).catch(() => setMensajeEmision({
                                            tipo: 'danger',
                                            texto: 'No se pudo abrir el PDF del comprobante.',
                                        }))}
                                        className="d-inline-flex align-items-center gap-1 mt-2 text-decoration-none"
                                        style={{ fontSize: 13 }}
                                    >
                                        <i className="bi bi-file-earmark-pdf" style={{ fontSize: 16 }} />
                                        Ver PDF
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                );
            })()},

            <div className="row g-3 mb-4">
                {/* ── Datos de Factura ─────────────────────────── */}
                <div className="col-md-6">
                    <div className="card border-0 shadow-sm h-100">
                        <div className="card-header text-white py-2" style={{ background: "#2F6FED" }}>
                            <strong>Datos de Factura</strong>
                        </div>
                        <div className="card-body">
                            <div className="row g-3">
                                <div className="col-md-6">
                                    <label className="form-label text-muted small">Cliente</label>
                                    <div className="fw-semibold">{creditNote.customer_name}</div>
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label text-muted small">Tipo Comprobante</label>
                                    <div className="fw-semibold">{creditNote.tipo_comprobante_modificado}</div>
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label text-muted small">Nro. Comprobante</label>
                                    <div className="fw-semibold">{creditNote.numero_comprobante_modificado}</div>
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label text-muted small">Generar como</label>
                                    <div className="fw-semibold">{GENERAR_COMO_LABEL[creditNote.generar_como] || creditNote.generar_como}</div>
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label text-muted small">Almacén</label>
                                    <div className="fw-semibold">{creditNote.warehouse_name || "-"}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* ── Datos de Nota de Crédito ──────────────────── */}
                <div className="col-md-6">
                    <div className="card border-0 shadow-sm h-100">
                        <div className="card-header text-white py-2" style={{ background: "#2F6FED" }}>
                            <strong>Datos de Nota de Crédito</strong>
                        </div>
                        <div className="card-body">
                            <div className="row g-3">
                                <div className="col-md-6">
                                    <label className="form-label text-muted small">Fecha de Emisión</label>
                                    <div className="fw-semibold">{creditNote.date}</div>
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label text-muted small">Vendedor</label>
                                    <div className="fw-semibold">{creditNote.vendedor || "-"}</div>
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label text-muted small">Concepto</label>
                                    <div className="fw-semibold">{CONCEPTO_LABEL[creditNote.concepto] || creditNote.concepto}</div>
                                </div>
                                <div className="col-md-6">
                                    <label className="form-label text-muted small">Categoría</label>
                                    <div className="fw-semibold">{creditNote.category_name || "-"}</div>
                                </div>
                                <div className="col-12">
                                    <label className="form-label text-muted small">Motivo</label>
                                    <div className="fw-semibold">{creditNote.motivo}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="card border-0 shadow-sm mb-4">
                <div className="card-header text-white py-2" style={{ background: "#2F6FED" }}>
                    <strong>Detalles</strong>
                </div>
                <div className="card-body">
                    <div className="table-responsive">
                        <table className="table table-hover">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Producto</th>
                                    <th>Cantidad</th>
                                    <th>Precio</th>
                                    <th>Descuento</th>
                                    <th>IVA</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.length === 0 && (
                                    <tr><td colSpan={7} className="text-center text-muted">Sin productos.</td></tr>
                                )}
                                {items.map((item) => (
                                    <tr key={item.id}>
                                        <td>{item.product?.attributes?.code || item.product?.code}</td>
                                        <td>
                                            {item.product?.attributes?.name || item.product?.name}
                                            {(item.product?.attributes?.variation_type_name) && (
                                                <span className="badge bg-light-primary ms-1">
                                                    {item.product.attributes.variation_type_name}
                                                </span>
                                            )}
                                        </td>
                                        <td>{item.quantity}</td>
                                        <td>${Number(item.product_price || 0).toFixed(2)}</td>
                                        <td>${Number(item.discount_amount || 0).toFixed(2)}</td>
                                        <td>${Number(item.tax_amount || 0).toFixed(2)}</td>
                                        <td>${Number(item.sub_total || 0).toFixed(2)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="row justify-content-end mt-3">
                        <div className="col-md-4">
                            <div className="d-flex justify-content-between">
                                <span>Subtotal</span>
                                <span>${subtotal.toFixed(2)}</span>
                            </div>
                            <div className="d-flex justify-content-between">
                                <span>Descuento</span>
                                <span>${Number(creditNote.discount || 0).toFixed(2)}</span>
                            </div>
                            <div className="d-flex justify-content-between">
                                <span>IVA</span>
                                <span>${Number(creditNote.tax_amount || 0).toFixed(2)}</span>
                            </div>
                            <hr />
                            <div className="d-flex justify-content-between fs-5 fw-bold text-danger">
                                <span>Total Nota Crédito</span>
                                <span>${Number(creditNote.grand_total || 0).toFixed(2)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </MasterLayout>
    );
};

export default CreditNoteDetails;
