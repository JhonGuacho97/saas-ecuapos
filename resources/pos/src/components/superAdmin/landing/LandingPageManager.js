import React, { useCallback, useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
    faArrowDown,
    faArrowUp,
    faCloudArrowUp,
    faEye,
    faFileLines,
    faFloppyDisk,
    faGrip,
    faLayerGroup,
    faPen,
    faPlus,
    faToggleOn,
    faTrash,
} from "@fortawesome/free-solid-svg-icons";
import {
    getLandingPage,
    updateLandingPage,
    uploadLandingImage,
} from "./landingPageApi";
import "../styles/landing-page-manager.scss";

const tabs = [
    ["general", "Principal"],
    ["services", "Servicios"],
    ["benefits", "Beneficios"],
    ["steps", "Pasos"],
    ["testimonials", "Testimonios"],
    ["faqs", "Preguntas"],
    ["partners", "Aliados"],
    ["legal", "Páginas legales"],
];
const schemas = {
    services: [
        ["title", "Título"],
        ["description", "Descripción", "textarea"],
        ["icon", "Icono interno"],
    ],
    benefits: [
        ["title", "Título"],
        ["description", "Descripción", "textarea"],
        ["icon", "Icono interno"],
    ],
    steps: [
        ["number", "Número"],
        ["title", "Título"],
        ["description", "Descripción", "textarea"],
    ],
    testimonials: [
        ["name", "Nombre"],
        ["role", "Cargo o negocio"],
        ["quote", "Testimonio", "textarea"],
        ["avatar", "Fotografía", "image"],
    ],
    faqs: [
        ["question", "Pregunta"],
        ["answer", "Respuesta", "textarea"],
    ],
    partners: [
        ["name", "Nombre"],
        ["image", "Logotipo", "image"],
    ],
};
const emptyFor = (section) => ({
    id: `${section}-${Date.now()}-${Math.random().toString(16).slice(2)}`,
    is_active: true,
    ...(section === "steps" ? { number: "01" } : {}),
});

export default function LandingPageManager({ setNotice }) {
    const [data, setData] = useState(null);
    const [searchParams, setSearchParams] = useSearchParams();
    const requestedSection = searchParams.get("section");
    const tab = tabs.some(([key]) => key === requestedSection)
        ? requestedSection
        : "general";
    const [editing, setEditing] = useState(null);
    const [saving, setSaving] = useState(false);
    const [uploading, setUploading] = useState(false);
    const load = useCallback(async () => {
        try {
            setData(await getLandingPage());
        } catch (error) {
            setNotice({
                type: "error",
                text:
                    error.response?.data?.message ||
                    "No se pudo cargar la landing page.",
            });
        }
    }, [setNotice]);
    useEffect(() => {
        load();
    }, [load]);

    const setContent = (updater) =>
        setData((current) => ({
            ...current,
            content: updater(current.content),
        }));
    const setGeneral = (key, value) =>
        setContent((content) => ({
            ...content,
            general: { ...content.general, [key]: value },
        }));
    const setLegal = (key, value) =>
        setContent((content) => ({
            ...content,
            legal: { ...content.legal, [key]: value },
        }));
    const save = async () => {
        setSaving(true);
        try {
            const response = await updateLandingPage({
                content: data.content,
                is_published: data.is_published,
            });
            setData((current) => ({ ...current, ...response }));
            setNotice({ text: "Landing page guardada y actualizada." });
        } catch (error) {
            setNotice({
                type: "error",
                text:
                    error.response?.data?.message ||
                    "No se pudo guardar la landing page.",
            });
        } finally {
            setSaving(false);
        }
    };
    const upload = async (file, section) => {
        if (!file) return null;
        setUploading(true);
        try {
            return await uploadLandingImage(file, section);
        } catch (error) {
            setNotice({
                type: "error",
                text:
                    error.response?.data?.message ||
                    "No se pudo subir la imagen.",
            });
            return null;
        } finally {
            setUploading(false);
        }
    };
    const saveItem = (item) => {
        setContent((content) => {
            const exists = content[tab].some(
                (current) => current.id === item.id,
            );
            return {
                ...content,
                [tab]: exists
                    ? content[tab].map((current) =>
                          current.id === item.id ? item : current,
                      )
                    : [...content[tab], item],
            };
        });
        setEditing(null);
    };
    const alterItem = (id, action) =>
        setContent((content) => {
            const items = [...content[tab]],
                index = items.findIndex((item) => item.id === id);
            if (action === "delete") items.splice(index, 1);
            if (action === "toggle")
                items[index] = {
                    ...items[index],
                    is_active: !items[index].is_active,
                };
            if (action === "up" && index > 0)
                [items[index - 1], items[index]] = [
                    items[index],
                    items[index - 1],
                ];
            if (action === "down" && index < items.length - 1)
                [items[index + 1], items[index]] = [
                    items[index],
                    items[index + 1],
                ];
            return { ...content, [tab]: items };
        });

    if (!data)
        return (
            <div className="sa-loading">
                <span /> Cargando contenido…
            </div>
        );
    return (
        <div className="lpm-page">
            <section className="lpm-hero">
                <div>
                    <span className="sa-eyebrow">PRESENCIA DIGITAL</span>
                    <h2>Landing page de EcuaPOS</h2>
                    <p>
                        Administra el contenido público sin editar archivos ni
                        desplegar una nueva versión.
                    </p>
                </div>
                <div className="lpm-hero-actions">
                    <label className="lpm-publish">
                        <input
                            type="checkbox"
                            checked={data.is_published}
                            onChange={(event) =>
                                setData({
                                    ...data,
                                    is_published: event.target.checked,
                                })
                            }
                        />
                        <span>
                            <FontAwesomeIcon icon={faToggleOn} />{" "}
                            {data.is_published ? "Publicada" : "Oculta"}
                        </span>
                    </label>
                    <a
                        className="sa-btn sa-btn--soft"
                        href="/"
                        target="_blank"
                        rel="noreferrer"
                    >
                        <FontAwesomeIcon icon={faEye} /> Vista previa
                    </a>
                    <button
                        className="sa-btn sa-btn--primary"
                        disabled={saving}
                        onClick={save}
                    >
                        <FontAwesomeIcon icon={faFloppyDisk} />{" "}
                        {saving ? "Guardando…" : "Guardar cambios"}
                    </button>
                </div>
            </section>
            <div className="lpm-workspace">
                <aside
                    className="lpm-tabs"
                    role="tablist"
                    aria-label="Secciones de la landing"
                >
                    {tabs.map(([key, label]) => (
                        <button
                            type="button"
                            role="tab"
                            aria-selected={tab === key}
                            key={key}
                            className={tab === key ? "active" : ""}
                            onClick={() =>
                                setSearchParams(
                                    { section: key },
                                    { replace: true },
                                )
                            }
                        >
                            <FontAwesomeIcon
                                icon={
                                    key === "legal" ? faFileLines : faLayerGroup
                                }
                            />
                            <span>{label}</span>
                        </button>
                    ))}
                </aside>
                <section className="lpm-editor">
                    {tab === "general" && (
                        <GeneralEditor
                            values={data.content.general}
                            onChange={setGeneral}
                            upload={upload}
                            uploading={uploading}
                        />
                    )}
                    {tab === "legal" && (
                        <LegalEditor
                            values={data.content.legal}
                            onChange={setLegal}
                        />
                    )}
                    {schemas[tab] && (
                        <CollectionEditor
                            section={tab}
                            items={data.content[tab]}
                            onAdd={() => setEditing(emptyFor(tab))}
                            onEdit={setEditing}
                            onAlter={alterItem}
                        />
                    )}
                </section>
            </div>
            {editing && (
                <ItemModal
                    section={tab}
                    item={editing}
                    onClose={() => setEditing(null)}
                    onSave={saveItem}
                    upload={upload}
                    uploading={uploading}
                />
            )}
        </div>
    );
}

function GeneralEditor({ values, onChange, upload, uploading }) {
    const fields = [
        ["brand_name", "Nombre de marca"],
        ["announcement", "Aviso superior"],
        ["eyebrow", "Texto superior del Hero"],
        ["hero_title", "Título principal", "textarea"],
        ["hero_description", "Descripción principal", "textarea"],
        ["hero_primary_label", "Botón principal"],
        ["hero_secondary_label", "Botón secundario"],
        ["services_title", "Título de servicios"],
        ["services_description", "Descripción de servicios", "textarea"],
        ["benefits_title", "Título de beneficios"],
        ["steps_title", "Título de pasos"],
        ["testimonials_title", "Título de testimonios"],
        ["faq_title", "Título de preguntas"],
        ["plans_title", "Título de planes"],
        ["contact_title", "Título de llamada final"],
        ["contact_description", "Descripción de llamada final", "textarea"],
        ["contact_email", "Correo de contacto"],
        ["whatsapp", "WhatsApp con código de país"],
        ["location", "Ubicación"],
        ["meta_title", "Título para buscadores"],
        ["meta_description", "Descripción para buscadores", "textarea"],
    ];
    return (
        <>
            <EditorHeading
                title="Contenido principal"
                text="Define el mensaje, contacto y textos de cada bloque de la página."
            />
            <div className="lpm-image-field">
                <div className="lpm-image-preview">
                    {values.hero_image ? (
                        <img src={values.hero_image} alt="Hero" />
                    ) : (
                        <div>
                            <FontAwesomeIcon icon={faCloudArrowUp} />
                            <span>Vista visual automática</span>
                        </div>
                    )}
                </div>
                <div>
                    <strong>Imagen principal</strong>
                    <p>
                        Opcional. Si no agregas una imagen, mostraremos el
                        mockup moderno de EcuaPOS.
                    </p>
                    <label className="sa-btn sa-btn--soft">
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={async (event) => {
                                const url = await upload(
                                    event.target.files?.[0],
                                    "hero",
                                );
                                if (url) onChange("hero_image", url);
                            }}
                        />
                        {uploading ? "Subiendo…" : "Elegir imagen"}
                    </label>
                    {values.hero_image && (
                        <button
                            className="lpm-text-button"
                            onClick={() => onChange("hero_image", "")}
                        >
                            Usar mockup predeterminado
                        </button>
                    )}
                </div>
            </div>
            <div className="lpm-form-grid">
                {fields.map(([key, label, type]) => (
                    <label
                        key={key}
                        className={type === "textarea" ? "wide" : ""}
                    >
                        <span>{label}</span>
                        {type === "textarea" ? (
                            <textarea
                                value={values[key] || ""}
                                onChange={(event) =>
                                    onChange(key, event.target.value)
                                }
                            />
                        ) : (
                            <input
                                value={values[key] || ""}
                                onChange={(event) =>
                                    onChange(key, event.target.value)
                                }
                            />
                        )}
                    </label>
                ))}
            </div>
        </>
    );
}
function LegalEditor({ values, onChange }) {
    return (
        <>
            <EditorHeading
                title="Páginas legales"
                text="Estos textos se muestran desde el pie de la landing."
            />
            <div className="lpm-legal-grid">
                {[
                    ["terms", "Términos y condiciones"],
                    ["privacy", "Política de privacidad"],
                    ["refunds", "Política de reembolsos"],
                ].map(([key, label]) => (
                    <label key={key}>
                        <span>{label}</span>
                        <textarea
                            value={values[key] || ""}
                            onChange={(event) =>
                                onChange(key, event.target.value)
                            }
                        />
                    </label>
                ))}
            </div>
        </>
    );
}
function EditorHeading({ title, text, action }) {
    return (
        <header className="lpm-editor-head">
            <div>
                <h3>{title}</h3>
                <p>{text}</p>
            </div>
            {action}
        </header>
    );
}
function CollectionEditor({ section, items, onAdd, onEdit, onAlter }) {
    const labels = Object.fromEntries(tabs);
    return (
        <>
            <EditorHeading
                title={labels[section]}
                text="Ordena, edita u oculta elementos sin eliminarlos definitivamente."
                action={
                    <button className="sa-btn sa-btn--primary" onClick={onAdd}>
                        <FontAwesomeIcon icon={faPlus} /> Agregar
                    </button>
                }
            />
            <div className="lpm-items">
                {items.length ? (
                    items.map((item, index) => (
                        <article
                            key={item.id}
                            className={!item.is_active ? "disabled" : ""}
                        >
                            <span className="lpm-drag">
                                <FontAwesomeIcon icon={faGrip} />
                            </span>
                            {item.image || item.avatar ? (
                                <img src={item.image || item.avatar} alt="" />
                            ) : (
                                <span className="lpm-item-number">
                                    {item.number ||
                                        String(index + 1).padStart(2, "0")}
                                </span>
                            )}
                            <div>
                                <strong>
                                    {item.title ||
                                        item.name ||
                                        item.question ||
                                        "Elemento sin título"}
                                </strong>
                                <p>
                                    {item.description ||
                                        item.quote ||
                                        item.answer ||
                                        "Completa la información de este elemento."}
                                </p>
                            </div>
                            <span
                                className={`sa-status ${item.is_active ? "sa-status--active" : "sa-status--suspended"}`}
                            >
                                {item.is_active ? "Visible" : "Oculto"}
                            </span>
                            <div className="lpm-item-actions">
                                <button
                                    disabled={index === 0}
                                    onClick={() => onAlter(item.id, "up")}
                                    title="Subir"
                                >
                                    <FontAwesomeIcon icon={faArrowUp} />
                                </button>
                                <button
                                    disabled={index === items.length - 1}
                                    onClick={() => onAlter(item.id, "down")}
                                    title="Bajar"
                                >
                                    <FontAwesomeIcon icon={faArrowDown} />
                                </button>
                                <button
                                    onClick={() => onAlter(item.id, "toggle")}
                                    title="Cambiar visibilidad"
                                >
                                    <FontAwesomeIcon icon={faToggleOn} />
                                </button>
                                <button
                                    onClick={() => onEdit(item)}
                                    title="Editar"
                                >
                                    <FontAwesomeIcon icon={faPen} />
                                </button>
                                <button
                                    className="danger"
                                    onClick={() => onAlter(item.id, "delete")}
                                    title="Eliminar"
                                >
                                    <FontAwesomeIcon icon={faTrash} />
                                </button>
                            </div>
                        </article>
                    ))
                ) : (
                    <div className="lpm-empty">
                        <FontAwesomeIcon icon={faLayerGroup} />
                        <strong>Aún no hay elementos</strong>
                        <p>
                            Agrega el primero para mostrar esta sección en la
                            landing.
                        </p>
                        <button
                            className="sa-btn sa-btn--primary"
                            onClick={onAdd}
                        >
                            Agregar elemento
                        </button>
                    </div>
                )}
            </div>
        </>
    );
}
function ItemModal({ section, item, onClose, onSave, upload, uploading }) {
    const [form, setForm] = useState({ ...item });
    const imageSection = section === "partners" ? "partners" : "testimonials";
    return (
        <div
            className="sa-modal-backdrop"
            onMouseDown={(event) =>
                event.target === event.currentTarget && onClose()
            }
        >
            <div className="sa-modal lpm-modal">
                <header>
                    <div>
                        <span className="sa-eyebrow">
                            CONTENIDO DE LA LANDING
                        </span>
                        <h2>
                            {item.title || item.name || item.question
                                ? "Editar elemento"
                                : "Nuevo elemento"}
                        </h2>
                    </div>
                    <button onClick={onClose}>×</button>
                </header>
                <div className="sa-modal-body">
                    <div className="lpm-form-grid">
                        {schemas[section].map(([key, label, type]) => (
                            <label
                                key={key}
                                className={
                                    type === "textarea" || type === "image"
                                        ? "wide"
                                        : ""
                                }
                            >
                                <span>{label}</span>
                                {type === "textarea" ? (
                                    <textarea
                                        value={form[key] || ""}
                                        onChange={(event) =>
                                            setForm({
                                                ...form,
                                                [key]: event.target.value,
                                            })
                                        }
                                    />
                                ) : type === "image" ? (
                                    <div className="lpm-upload-inline">
                                        {form[key] && (
                                            <img src={form[key]} alt="" />
                                        )}
                                        <label className="sa-btn sa-btn--soft">
                                            <input
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp"
                                                onChange={async (event) => {
                                                    const url = await upload(
                                                        event.target.files?.[0],
                                                        imageSection,
                                                    );
                                                    if (url)
                                                        setForm((current) => ({
                                                            ...current,
                                                            [key]: url,
                                                        }));
                                                }}
                                            />
                                            {uploading
                                                ? "Subiendo…"
                                                : "Subir imagen"}
                                        </label>
                                    </div>
                                ) : (
                                    <input
                                        value={form[key] || ""}
                                        onChange={(event) =>
                                            setForm({
                                                ...form,
                                                [key]: event.target.value,
                                            })
                                        }
                                    />
                                )}
                            </label>
                        ))}
                    </div>
                    <label className="sa-check">
                        <input
                            type="checkbox"
                            checked={form.is_active}
                            onChange={(event) =>
                                setForm({
                                    ...form,
                                    is_active: event.target.checked,
                                })
                            }
                        />
                        <span>Mostrar este elemento en la landing</span>
                    </label>
                </div>
                <footer>
                    <button className="sa-btn sa-btn--soft" onClick={onClose}>
                        Cancelar
                    </button>
                    <button
                        className="sa-btn sa-btn--primary"
                        disabled={uploading}
                        onClick={() => onSave(form)}
                    >
                        Guardar elemento
                    </button>
                </footer>
            </div>
        </div>
    );
}
