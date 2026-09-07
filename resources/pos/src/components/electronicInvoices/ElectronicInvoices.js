import React, { useEffect, useState, useCallback } from "react";
import { useDispatch } from "react-redux";
import dayjs from "dayjs";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faMagnifyingGlass, faTriangleExclamation, faFileInvoice, faSliders } from "@fortawesome/free-solid-svg-icons";
import { useNavigate } from "react-router-dom";
import MasterLayout from "../MasterLayout";
import HeaderTitle from "../header/HeaderTitle";
import apiConfig from "../../config/apiConfig";
import { addToast } from "../../store/action/toastAction";
import { getFormattedMessage } from "../../shared/sharedMethod";
import { ElectronicInvoiceStatusBadge } from "../sri/ElectronicInvoiceStatus";
import RutaEmisionPanel from "../sri/RutaEmisionPanel";
import ResourceListHeader from "../../shared/components/ResourceListHeader";
import { downloadAuthenticatedFile } from "../../shared/downloadAuthenticatedFile";
import "../../assets/scss/custom/pages/resource-list.scss";
import "../../assets/scss/custom/pages/fiscal-documents.scss";

const ESTADOS_SRI = [
    { value: "TODOS", label: "Todos" },
    { value: "PENDIENTE", label: "Pendiente" },
    { value: "RECIBIDA", label: "Enviada, esperando autorización" },
    { value: "AUTORIZADA", label: "Autorizada" },
    { value: "NO_AUTORIZADA", label: "Rechazada" },
    { value: "DEVUELTA", label: "Devuelta por el SRI" },
    { value: "ERROR_TEMPORAL_SRI", label: "Error temporal del SRI" },
];

const TIPOS_COMPROBANTE = [
    { value: "TODOS", label: "Todos" },
    { value: "01", label: "Factura" },
    { value: "04", label: "Nota de crédito" },
    { value: "05", label: "Nota de débito" },
];

const TIPO_COMPROBANTE_LABEL = {
    "01": "Factura",
    "04": "Nota de crédito",
    "05": "Nota de débito",
};

// Antes usaban toISOString(), que siempre da la fecha en UTC -- en
// Ecuador (UTC-5), entre las 19:00 y 23:59 hora local el día en UTC ya
// es el día siguiente, así que el filtro "Hasta" por defecto mostraba
// mañana en vez de hoy. dayjs() sin .utc() usa la hora local.
const hoy = () => dayjs().format('YYYY-MM-DD');
const inicioDeMes = () => dayjs().startOf('month').format('YYYY-MM-DD');

const ElectronicInvoices = () => {
    const dispatch = useDispatch();
    const navigate = useNavigate();

    const filtrosPorDefecto = {
        fecha_desde: inicioDeMes(),
        fecha_hasta: hoy(),
        tipo_comprobante: "TODOS",
        estado: "TODOS",
    };

    // "filtros" es lo que el usuario va tocando en fecha/tipo/estado
    // (borrador). "filtrosAplicados" es lo que realmente se usa para
    // buscar -- solo se actualiza al hacer clic en "Buscar", así esos
    // campos no disparan una búsqueda con cada cambio.
    //
    // El texto de búsqueda es aparte: SÍ busca en vivo (con un pequeño
    // debounce), sin necesidad del botón.
    const [filtros, setFiltros] = useState(filtrosPorDefecto);
    const [filtrosAplicados, setFiltrosAplicados] = useState(filtrosPorDefecto);
    const [search, setSearch] = useState("");

    const [documentos, setDocumentos] = useState([]);
    const [resumen, setResumen] = useState({ no_autorizados: 0, saldo_total: 0 });
    const [rutaAbiertaId, setRutaAbiertaId] = useState(null);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
    const [loading, setLoading] = useState(false);
    const [pageSize, setPageSize] = useState(10);

    const cargar = useCallback((page = 1, overrides = {}) => {
        setLoading(true);
        const params = { ...filtrosAplicados, search, per_page: pageSize, ...overrides, page };
        // "TODOS" es solo un valor de UI -- no se manda como filtro real.
        if (params.tipo_comprobante === "TODOS") delete params.tipo_comprobante;
        if (params.estado === "TODOS") delete params.estado;
        if (!params.search) delete params.search;

        apiConfig
            .get("/electronic-invoices", { params })
            .then((res) => {
                setDocumentos(res.data.data.data || []);
                setResumen(res.data.resumen || { no_autorizados: 0, saldo_total: 0 });
                setMeta({
                    current_page: res.data.data.current_page,
                    last_page: res.data.data.last_page,
                    total: res.data.data.total,
                });
            })
            .catch((err) => {
                dispatch(addToast({
                    text: err.response?.data?.message || "Error al cargar los documentos electrónicos",
                    type: "error",
                }));
            })
            .finally(() => setLoading(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filtrosAplicados, search, pageSize]);

    // Carga inicial, una sola vez.
    useEffect(() => {
        cargar(1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Búsqueda en vivo del texto (con debounce) -- no dispara en el
    // primer render, solo cuando el usuario efectivamente escribe algo.
    const primerRender = React.useRef(true);
    useEffect(() => {
        if (primerRender.current) {
            primerRender.current = false;
            return;
        }
        const timer = setTimeout(() => {
            cargar(1, { search });
        }, 400);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const onFiltroChange = (e) => {
        const { name, value } = e.target;
        setFiltros((prev) => ({ ...prev, [name]: value }));
    };

    const onBuscar = (e) => {
        e.preventDefault();
        setFiltrosAplicados(filtros);
        cargar(1, { ...filtros, search });
    };

    const reintentar = async (saleId) => {
        try {
            await apiConfig.post(`/sales/${saleId}/electronic-invoice/reintentar`);
            dispatch(addToast({ text: "Reintentando la emisión de la factura electrónica." }));
            cargar(meta.current_page);
        } catch (err) {
            dispatch(addToast({
                text: err.response?.data?.message || "Error al reintentar la factura.",
                type: "error",
            }));
        }
    };

    const money = (n) => `$ ${Number(n || 0).toFixed(2)}`;

    const autorizadasVisibles = documentos.filter(
        (documento) => documento.estado === "AUTORIZADA"
    ).length;
    const pendientesVisibles = documentos.filter(
        (documento) => documento.estado !== "AUTORIZADA"
    ).length;
    const conflictosSecuencial = documentos.filter((documento) => documento.conflicto_secuencial);

    const abrirAjusteSecuencial = (documento) => {
        const params = new URLSearchParams({
            sequence_error: "1",
            type: documento.tipo_comprobante,
            ambiente: String(documento.ambiente || ""),
            estab: documento.estab || "",
            pto_emi: documento.pto_emi || "",
        });
        navigate(`/app/sri-config?${params.toString()}`);
    };
    const documentStats = [
        {
            label: "Documentos encontrados",
            value: meta.total || 0,
            helper: "Coinciden con los filtros",
            tone: "primary",
        },
        {
            label: "Autorizados visibles",
            value: autorizadasVisibles,
            helper: documentos.length + " en esta página",
            tone: "success",
        },
        {
            label: "Por revisar",
            value: resumen.no_autorizados || pendientesVisibles,
            helper: "Pendientes o con novedad",
            tone: resumen.no_autorizados ? "warning" : "success",
        },
        {
            label: "Saldo pendiente",
            value: money(resumen.saldo_total),
            helper: "Facturas electrónicas",
        },
    ];

    return (
        <MasterLayout>
            <HeaderTitle title={getFormattedMessage("electronic-invoices.title")} to="/app/dashboard" />

            <div className="resource-list-v2 fiscal-documents-v2 electronic-invoices-v2">
                <ResourceListHeader
                    eyebrow="Control tributario"
                    title={getFormattedMessage("electronic-invoices.title")}
                    description="Consulta el ciclo de emisión, autorización y cobro de tus comprobantes enviados al SRI."
                    type="electronic"
                    stats={documentStats}
                />

            <form className="fiscal-filter-panel" onSubmit={onBuscar}>
                <div className="fiscal-panel-heading">
                    <div>
                        <h2>Filtros de documentos</h2>
                        <p>Delimita el período, tipo y estado tributario que necesitas revisar.</p>
                    </div>
                </div>
                    <div className="fiscal-filter-grid">
                        <div className="fiscal-filter-field">
                            <label className="form-label">Fecha inicio:</label>
                            <input
                                type="date"
                                name="fecha_desde"
                                className="form-control"
                                value={filtros.fecha_desde}
                                onChange={onFiltroChange}
                            />
                        </div>
                        <div className="fiscal-filter-field">
                            <label className="form-label">Fecha fin:</label>
                            <input
                                type="date"
                                name="fecha_hasta"
                                className="form-control"
                                value={filtros.fecha_hasta}
                                onChange={onFiltroChange}
                            />
                        </div>
                        <div className="fiscal-filter-field">
                            <label className="form-label">Tipo comprobante:</label>
                            <select
                                name="tipo_comprobante"
                                className="form-select"
                                value={filtros.tipo_comprobante}
                                onChange={onFiltroChange}
                            >
                                {TIPOS_COMPROBANTE.map((t) => (
                                    <option key={t.value} value={t.value}>{t.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="fiscal-filter-field">
                            <label className="form-label">Estado SRI:</label>
                            <select
                                name="estado"
                                className="form-select"
                                value={filtros.estado}
                                onChange={onFiltroChange}
                            >
                                {ESTADOS_SRI.map((e) => (
                                    <option key={e.value} value={e.value}>{e.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="fiscal-filter-field fiscal-filter-action">
                            <button type="submit" className="btn btn-primary w-100">
                                <FontAwesomeIcon icon={faMagnifyingGlass} className="me-2" />
                                Buscar
                            </button>
                        </div>
                        <div className="fiscal-filter-field fiscal-filter-field--search">
                            <label className="form-label">Cliente / número de documento / referencia / clave de acceso:</label>
                            <input
                                type="text"
                                name="search"
                                className="form-control"
                                placeholder="Buscar por cliente, número de documento, número de venta o clave de acceso"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <div className="fiscal-filter-hint">Este campo filtra automáticamente mientras escribes.</div>
                        </div>
                    </div>
            </form>

            {resumen.no_autorizados > 0 && (
                <div className="fiscal-alert">
                    <FontAwesomeIcon icon={faTriangleExclamation} />
                    <span>
                        <strong>Atención:</strong> tienes <strong>{resumen.no_autorizados}</strong> documento
                        {resumen.no_autorizados === 1 ? "" : "s"} sin autorizar por el SRI (pendientes, rechazados
                        o devueltos). Revísalos y reintenta los que correspondan.
                    </span>
                </div>
            )}

            {conflictosSecuencial.length > 0 && (
                <div className="fiscal-alert fiscal-alert--sequence">
                    <FontAwesomeIcon icon={faTriangleExclamation} />
                    <span>
                        <strong>Numeración duplicada:</strong> el SRI ya registró el secuencial de {conflictosSecuencial.length === 1 ? "un comprobante" : `${conflictosSecuencial.length} comprobantes`} de esta página.
                    </span>
                    <button
                        type="button"
                        className="btn btn-sm btn-primary ms-auto"
                        onClick={() => abrirAjusteSecuencial(conflictosSecuencial[0])}
                    >
                        <FontAwesomeIcon icon={faSliders} className="me-2" />
                        Corregir numeración
                    </button>
                </div>
            )}

            <div className="fiscal-table-shell">
                <div className="fiscal-toolbar">
                    <div className="fiscal-toolbar-copy">
                        <strong>Comprobantes emitidos</strong>
                        <span>Abre el número del documento para consultar su ruta de emisión.</span>
                    </div>
                    <label className="fiscal-page-size">
                        Mostrar
                        <select
                            className="form-select"
                            value={pageSize}
                            onChange={(e) => {
                                const nuevoTamano = Number(e.target.value);
                                setPageSize(nuevoTamano);
                                cargar(1, { search, per_page: nuevoTamano });
                            }}
                        >
                            {[5, 10, 25, 50].map((n) => (
                                <option key={n} value={n}>{n}</option>
                            ))}
                        </select>
                        registros
                    </label>
                </div>
                <div className="fiscal-table-wrap">
                    <table className="table align-middle fiscal-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>N° Documento</th>
                                <th>Tipo</th>
                                <th>Cliente</th>
                                <th>Estado</th>
                                <th>Firmado</th>
                                <th className="text-end">Total</th>
                                <th className="text-end">Saldo</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && (
                                <tr>
                                    <td colSpan={9} className="text-center py-4">
                                        <span className="spinner-border spinner-border-sm me-2" />
                                        Cargando...
                                    </td>
                                </tr>
                            )}
                            {!loading && documentos.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-center py-4 text-muted">
                                        No se encontraron documentos electrónicos con estos filtros.
                                    </td>
                                </tr>
                            )}
                            {!loading && documentos.map((doc) => (
                                <tr key={doc.id}>
                                    <td>{doc.fecha ? doc.fecha.slice(0, 16).replace("T", " ") : "-"}</td>
                                    <td>
                                        <button
                                            type="button"
                                            className="fiscal-document-link"
                                            onClick={() => setRutaAbiertaId(doc.id)}
                                        >
                                            {doc.numero_comprobante}
                                        </button>
                                    </td>
                                    <td>
                                        <span className="fiscal-type-badge">
                                            {TIPO_COMPROBANTE_LABEL[doc.tipo_comprobante] || "Factura"}
                                        </span>
                                    </td>
                                    <td>{doc.cliente || "Consumidor Final"}</td>
                                    <td style={{ minWidth: 140 }}>
                                        <ElectronicInvoiceStatusBadge
                                            estado={doc.estado}
                                            data={doc}
                                            onReintentar={() => reintentar(doc.sale_id)}
                                        />
                                    </td>
                                    <td>
                                        <span className={"fiscal-signature-badge fiscal-signature-badge--" + (doc.firmado ? "yes" : "no")}>
                                            {doc.firmado ? "Firmado" : "Sin firma"}
                                        </span>
                                    </td>
                                    <td className="text-end">{money(doc.total)}</td>
                                    <td className="text-end">{money(doc.saldo)}</td>
                                    <td>
                                        {doc.conflicto_secuencial && (
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-warning fiscal-action-button me-1"
                                                title="Ajustar numeración SRI"
                                                onClick={() => abrirAjusteSecuencial(doc)}
                                            >
                                                <FontAwesomeIcon icon={faSliders} />
                                            </button>
                                        )}
                                        {doc.estado === "AUTORIZADA" && (
                                            <button
                                                type="button"
                                                onClick={() => downloadAuthenticatedFile(
                                                    `/api/electronic-invoices/${doc.id}/ride`,
                                                    { open: true }
                                                ).catch(() => dispatch(addToast({
                                                    text: "No se pudo abrir el RIDE.",
                                                    type: "error",
                                                })))}
                                                className="btn btn-sm btn-outline-success fiscal-action-button"
                                                title="Descargar RIDE"
                                            >
                                                <FontAwesomeIcon icon={faFileInvoice} />
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="fiscal-pagination">
                    <div className="d-flex gap-2">
                        <button
                            className="btn"
                            disabled={meta.current_page <= 1}
                            onClick={() => cargar(meta.current_page - 1)}
                        >
                            ‹ Anterior
                        </button>
                        <button
                            className="btn"
                            disabled={meta.current_page >= meta.last_page}
                            onClick={() => cargar(meta.current_page + 1)}
                        >
                            Siguiente ›
                        </button>
                    </div>
                    <span className="fiscal-pagination-total">
                        Total registros: {meta.total}
                    </span>
                </div>
            </div>

            <RutaEmisionPanel
                electronicInvoiceId={rutaAbiertaId}
                show={Boolean(rutaAbiertaId)}
                onHide={() => setRutaAbiertaId(null)}
                onReintentar={() => {
                    const doc = documentos.find((d) => d.id === rutaAbiertaId);
                    if (doc) reintentar(doc.sale_id);
                }}
            />
            </div>
        </MasterLayout>
    );
};

export default ElectronicInvoices;
