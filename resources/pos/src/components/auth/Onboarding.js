import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useDispatch } from 'react-redux';
import apiConfig from '../../config/apiConfig';
import { loginAction } from '../../store/action/authAction';
import TabTitle from '../../shared/tab-title/TabTitle';
import { onboardingStyles } from './styles/LoginStyles';

const initialValues = {
    organization_name: '',
    store_name: '',
    warehouse_name: 'Bodega principal',
    city: '',
    province: 'Manabí',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    terms: false,
};

const Onboarding = () => {
    const dispatch = useDispatch();
    const navigate = useNavigate();
    const [step, setStep] = useState(1);
    const [values, setValues] = useState(initialValues);
    const [errors, setErrors] = useState({});
    const [serverError, setServerError] = useState('');
    const [loading, setLoading] = useState(false);

    const change = ({ target }) => {
        const value = target.type === 'checkbox' ? target.checked : target.value;
        setValues((current) => ({ ...current, [target.name]: value }));
        setErrors((current) => ({ ...current, [target.name]: '' }));
        setServerError('');
    };

    const validateStep = (currentStep) => {
        const nextErrors = {};
        const required = currentStep === 1
            ? ['organization_name', 'city']
            : ['first_name', 'last_name', 'email', 'phone', 'password', 'password_confirmation'];
        required.forEach((key) => {
            if (!String(values[key] || '').trim()) nextErrors[key] = 'Este campo es obligatorio.';
        });

        if (currentStep === 2) {
            if (values.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
                nextErrors.email = 'Ingrese un correo electrónico válido.';
            }
            if (values.password && values.password.length < 8) {
                nextErrors.password = 'Use al menos 8 caracteres, con letras y números.';
            }
            if (values.password !== values.password_confirmation) {
                nextErrors.password_confirmation = 'Las contraseñas no coinciden.';
            }
            if (!values.terms) nextErrors.terms = 'Debe aceptar los términos para continuar.';
        }

        setErrors(nextErrors);
        return Object.keys(nextErrors).length === 0;
    };

    const continueToOwner = () => {
        if (validateStep(1)) setStep(2);
    };

    const submit = async (event) => {
        event.preventDefault();
        if (!validateStep(2)) return;

        setLoading(true);
        setServerError('');
        try {
            await apiConfig.post('onboarding/register', values);

            const credentials = new FormData();
            credentials.append('email', values.email);
            credentials.append('password', values.password);
            credentials.append('language_code', 'sp');
            dispatch(loginAction(credentials, navigate, setLoading));
        } catch (error) {
            setServerError(error?.response?.data?.message || 'No pudimos crear la cuenta. Inténtelo nuevamente.');
            setLoading(false);
        }
    };

    const field = (name, label, options = {}) => (
        <div className={`lp-field ${options.full ? 'ob-full' : ''}`}>
            <div className="lp-field-header"><label className="lp-label" htmlFor={`ob-${name}`}>{label}</label></div>
            <div className="lp-input-wrap">
                <input
                    id={`ob-${name}`}
                    className={`lp-input${errors[name] ? ' lp-input--error' : ''}`}
                    name={name}
                    type={options.type || 'text'}
                    value={values[name]}
                    onChange={change}
                    placeholder={options.placeholder || ''}
                    autoComplete={options.autoComplete}
                />
            </div>
            {errors[name] && <span className="lp-error-msg">{errors[name]}</span>}
        </div>
    );

    return (
        <>
            <style>{onboardingStyles}</style>
            <TabTitle title="Crear cuenta EcuaPos" />
            <div className="lp-root ob-root">
                <aside className="lp-aside ob-aside">
                    <div className="lp-hero">
                        <span className="lp-hero-eyebrow">Empieza con EcuaPos</span>
                        <h1 className="lp-hero-title">Tu negocio listo para<br /><span>vender desde hoy</span></h1>
                        <p className="lp-hero-sub">Crearemos tu empresa, tienda, bodega y caja principal en un solo proceso seguro.</p>
                        <div className="ob-benefits">
                            <div><b>01</b><span>Inventario y productos organizados</span></div>
                            <div><b>02</b><span>POS, caja y ventas preparados</span></div>
                            <div><b>03</b><span>Facturación electrónica configurable</span></div>
                        </div>
                    </div>
                    <div className="ob-aside-note">Tus datos quedan separados de los demás negocios.</div>
                </aside>

                <main className="lp-main ob-main">
                    <div className="lp-card ob-card">
                        <div className="ob-topline">
                            <Link to="/login" className="ob-back">← Volver al acceso</Link>
                            <span>Paso {step} de 2</span>
                        </div>
                        <div className="ob-progress"><i style={{ width: `${step * 50}%` }} /></div>
                        <span className="lp-card-badge-mobile">Cuenta empresarial EcuaPos</span>
                        <h2 className="lp-heading">{step === 1 ? 'Configura tu negocio' : 'Crea tu cuenta propietaria'}</h2>
                        <p className="lp-sub">{step === 1 ? 'Estos datos identificarán tu espacio de trabajo.' : 'Usarás estas credenciales para administrar EcuaPos.'}</p>

                        {serverError && <div className="ob-server-error">{serverError}</div>}

                        {step === 1 ? (
                            <div className="ob-form-grid">
                                {field('organization_name', 'Nombre de la empresa', { full: true, placeholder: 'Ej. Comercial Rivera' })}
                                {field('store_name', 'Nombre de la tienda', { placeholder: 'Opcional: Matriz' })}
                                {field('warehouse_name', 'Bodega principal')}
                                {field('city', 'Ciudad', { placeholder: 'Ej. Manta' })}
                                {field('province', 'Provincia')}
                                <div className="ob-full">
                                    <button type="button" className="lp-btn" onClick={continueToOwner}>Continuar <span>→</span></button>
                                </div>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="ob-form-grid" noValidate>
                                {field('first_name', 'Nombres', { autoComplete: 'given-name' })}
                                {field('last_name', 'Apellidos', { autoComplete: 'family-name' })}
                                {field('email', 'Correo electrónico', { full: true, type: 'email', autoComplete: 'email' })}
                                {field('phone', 'Teléfono', { autoComplete: 'tel' })}
                                <div />
                                {field('password', 'Contraseña', { type: 'password', autoComplete: 'new-password' })}
                                {field('password_confirmation', 'Confirmar contraseña', { type: 'password', autoComplete: 'new-password' })}
                                <label className={`ob-terms ob-full${errors.terms ? ' ob-terms--error' : ''}`}>
                                    <input type="checkbox" name="terms" checked={values.terms} onChange={change} />
                                    <span>Acepto los términos de servicio y el tratamiento de datos.</span>
                                </label>
                                {errors.terms && <span className="lp-error-msg ob-full">{errors.terms}</span>}
                                <div className="ob-actions ob-full">
                                    <button type="button" className="ob-secondary" onClick={() => setStep(1)} disabled={loading}>Atrás</button>
                                    <button type="submit" className="lp-btn" disabled={loading}>
                                        <span className="lp-btn-inner">{loading && <span className="lp-spinner" />}{loading ? 'Preparando tu cuenta...' : 'Crear mi cuenta'}</span>
                                    </button>
                                </div>
                            </form>
                        )}
                        <div className="lp-card-foot">Configuración protegida · Sin datos compartidos</div>
                    </div>
                </main>
            </div>
        </>
    );
};

export default Onboarding;
