import React, { useEffect, useState } from "react";
import { connect } from "react-redux";
import { Form } from "react-bootstrap-v5";
import MasterLayout from "../MasterLayout";
import TabTitle from "../../shared/tab-title/TabTitle";
import {
    fetchSetting,
    editSetting,
    fetchCacheClear,
    fetchState,
} from "../../store/action/settingAction";
import { fetchCurrencies } from "../../store/action/currencyAction";
import { fetchAllCustomer } from "../../store/action/customerAction";
import { fetchAllWarehouses } from "../../store/action/warehouseAction";
import ImagePicker from "../../shared/image-picker/ImagePicker";
import {
    getFormattedMessage,
    numValidate,
    placeholderText,
} from "../../shared/sharedMethod";
import languages from "../../shared/option-lists/Language.json";
import sms from "../../shared/option-lists/Sms.json";
import ReactSelect from "../../shared/select/reactSelect";
import HeaderTitle from "../header/HeaderTitle";
import TopProgressBar from "../../shared/components/loaders/TopProgressBar";
import dateFormatOptions from "./dateFormatOptions.json";
import "./settings.scss";

const Settings = (props) => {
    const {
        fetchSetting,
        fetchCacheClear,
        fetchCurrencies,
        fetchAllCustomer,
        customers,
        fetchAllWarehouses,
        warehouses,
        editSetting,
        currencies,
        settings,
        fetchState,
        countryState,
        dateFormat,
        defaultCountry,
    } = props;

    const [settingValue, setSettingValue] = useState({
        currency: "",
        currency_symbol: "",
        email: "",
        logo: "",
        company_name: "",
        phone: "",
        developed: "",
        footer: "",
        default_language: "",
        default_customer: "",
        default_warehouse: "",
        warehouse_name: "",
        address: "",
        dateFormat: "",
        stripe_key: "",
        stripe_secret: "",
        sms_gateway: "",
        twillo_sid: "",
        twillo_token: "",
        twillo_from: "",
        smtp_host: "",
        smtp_port: "",
        smtp_username: "",
        smtp_password: "",
        smtp_Encryption: "",
        show_version_on_footer: "",
        show_logo_in_receipt: "",
        show_app_name_in_sidebar: "",
        country: "",
        countries: "",
        state: "",
        city: "",
        postCode: "",
        date_format: "",
        Currency_icon_Right_side: "",
    });

    const [defaultDate, setDefaultDate] = useState(null);
    const [imagePreviewUrl, setImagePreviewUrl] = useState();
    const [byDefaultCountry, setByDefaultCountry] = useState(null);
    const [selectImg, setSelectImg] = useState(null);
    const [errors, setErrors] = useState({
        currency: "",
        currency_symbol: "",
        email: "",
        company_name: "",
        phone: "",
        developed: "",
        footer: "",
        default_language: "",
        default_customer: "",
        default_warehouse: "",
        warehouse_name: "",
        address: "",
        stripe_key: "",
        stripe_secret: "",
        sms_gateway: "",
        twillo_sid: "",
        twillo_token: "",
        twillo_from: "",
        smtp_host: "",
        smtp_port: "",
        smtp_username: "",
        smtp_password: "",
        smtp_Encryption: "",
        show_version_on_footer: "",
        show_logo_in_receipt: "",
        show_app_name_in_sidebar: "",
        city: "",
        // postCode: '',
        country: "",
        date_format: "",
        Currency_icon_Right_side: "",
    });

    const [disable, setDisable] = React.useState(true);
    const [checked, setChecked] = useState(false);
    const [logoChecked, setLogoChecked] = useState(false);
    const [showAppName, setShowAppName] = useState(false);
    const [selectedLanguage] = useState(
        newLanguages
            ? [
                {
                    label: newLanguages[0].label,
                    value: newLanguages[0].value,
                },
            ]
            : null
    );
    const newLanguages = languages.filter((language) => language.value);

    const [selectedSms] = useState(
        newSms
            ? [
                {
                    label: newSms[0].label,
                    value: newSms[0].values,
                },
            ]
            : null
    );
    const newSms = sms.filter((item) => item.value);

    useEffect(() => {
        fetchSetting();
        fetchCurrencies();
        fetchAllCustomer();
        fetchAllWarehouses();
    }, []);

    useEffect(() => {
        if (settings) {
            setSettingValue({
                currency:
                    settings.attributes && settings.attributes.currency
                        ? {
                            value: Number(settings.attributes.currency),
                            label: settings.attributes.currency_symbol,
                        }
                        : "",
                currency_symbol:
                    settings.attributes && settings.attributes.currency_symbol
                        ? settings.attributes.currency_symbol
                        : "",
                email:
                    settings.attributes && settings.attributes.email
                        ? settings.attributes.email
                        : "",
                logo:
                    settings.attributes && settings.attributes.logo
                        ? settings.attributes.logo
                        : "",
                company_name:
                    settings.attributes && settings.attributes.company_name
                        ? settings.attributes.company_name
                        : "",
                phone:
                    settings.attributes && settings.attributes.phone
                        ? settings.attributes.phone
                        : "",
                developed:
                    settings.attributes && settings.attributes.developed
                        ? settings.attributes.developed
                        : "",
                footer:
                    settings.attributes && settings.attributes.footer
                        ? settings.attributes.footer
                        : "",
                default_language:
                    settings.attributes && settings.attributes.default_language
                        ? settings.attributes.default_language
                        : "",
                default_customer:
                    settings.attributes && settings.attributes.default_customer
                        ? {
                            value: Number(
                                settings.attributes.default_customer
                            ),
                            label: settings.attributes.customer_name,
                        }
                        : "",
                default_warehouse:
                    settings.attributes && settings.attributes.default_warehouse
                        ? {
                            value: Number(
                                settings.attributes.default_warehouse
                            ),
                            label: settings.attributes.warehouse_name,
                        }
                        : "",
                warehouse_name:
                    settings.attributes && settings.attributes.warehouse_name
                        ? settings.attributes.warehouse_name
                        : "",
                address:
                    settings.attributes && settings.attributes.address
                        ? settings.attributes.address
                        : "",
                stripe_key:
                    settings.attributes && settings.attributes.stripe_key
                        ? settings.attributes.stripe_key
                        : "",
                stripe_secret:
                    settings.attributes && settings.attributes.stripe_secret
                        ? settings.attributes.stripe_secret
                        : "",
                sms_gateway:
                    settings.attributes && settings.attributes.sms_gateway
                        ? settings.attributes.sms_gateway
                        : "",
                twillo_sid:
                    settings.attributes && settings.attributes.twillo_sid
                        ? settings.attributes.twillo_sid
                        : "",
                twillo_token:
                    settings.attributes && settings.attributes.twillo_token
                        ? settings.attributes.twillo_token
                        : "",
                twillo_from:
                    settings.attributes && settings.attributes.twillo_from
                        ? settings.attributes.twillo_from
                        : "",
                smtp_host:
                    settings.attributes && settings.attributes.smtp_host
                        ? settings.attributes.smtp_host
                        : "",
                smtp_port:
                    settings.attributes && settings.attributes.smtp_port
                        ? settings.attributes.smtp_port
                        : "",
                smtp_username:
                    settings.attributes && settings.attributes.smtp_username
                        ? settings.attributes.smtp_username
                        : "",
                smtp_password:
                    settings.attributes && settings.attributes.smtp_password
                        ? settings.attributes.smtp_password
                        : "",
                smtp_Encryption:
                    settings.attributes && settings.attributes.smtp_Encryption
                        ? settings.attributes.smtp_Encryption
                        : "",
                show_version_on_footer:
                    settings.attributes &&
                        settings.attributes.show_version_on_footer !== "1"
                        ? false
                        : true,
                show_logo_in_receipt:
                    settings.attributes &&
                        settings.attributes.show_logo_in_receipt !== "1"
                        ? false
                        : true,
                show_app_name_in_sidebar:
                    settings.attributes &&
                        settings.attributes.show_app_name_in_sidebar !== "1"
                        ? false
                        : true,
                city:
                    settings.attributes && settings.attributes.city
                        ? settings.attributes.city
                        : "",
                postCode:
                    settings.attributes && settings.attributes.postcode
                        ? settings.attributes.postcode
                        : "",
                countries:
                    settings.attributes &&
                        settings.attributes.countries &&
                        byDefaultCountry
                        ? {
                            value: byDefaultCountry.id,
                            label: byDefaultCountry.name,
                        }
                        : "",
                country:
                    settings.attributes && settings.attributes.country
                        ? {
                            value: settings.attributes.country,
                            label: settings.attributes.country,
                        }
                        : "",
                state:
                    settings.attributes && settings.attributes.country
                        ? {
                            value: settings.attributes.state,
                            label: settings.attributes.state,
                        }
                        : "",
                date_format:
                    settings.attributes &&
                        settings.attributes.date_format &&
                        defaultDate
                        ? { value: defaultDate.value, label: defaultDate.label }
                        : "",
                Currency_icon_Right_side:
                    settings.attributes &&
                        settings.attributes.is_currency_right !== "true"
                        ? false
                        : true,
            });
            if (
                settings.attributes &&
                settings.attributes.show_version_on_footer === "1"
            ) {
                setChecked(true);
            } else {
                setChecked(false);
            }
            if (
                settings.attributes &&
                settings.attributes.show_logo_in_receipt === "1"
            ) {
                setLogoChecked(true);
            } else {
                setLogoChecked(false);
            }

            if (
                settings.attributes &&
                settings.attributes.show_app_name_in_sidebar === "1"
            ) {
                setShowAppName(true);
            } else {
                setShowAppName(false);
            }
        }
    }, [settings, defaultDate]);

    useEffect(() => {
        if (dateFormat) {
            const defaultDateFormat = dateFormat
                ? dateFormatOptions.filter((date) => date.value === dateFormat)
                : null;
            defaultDateFormat && setDefaultDate(defaultDateFormat[0]);
        }
    }, [dateFormat]);

    useEffect(() => {
        if (defaultCountry) {
            const countries =
                defaultCountry &&
                defaultCountry.countries &&
                defaultCountry.countries.filter(
                    (country) => country.name === defaultCountry.country
                );
            countries && setByDefaultCountry(countries[0]);
        }
    }, [defaultCountry]);

    useEffect(() => {
        byDefaultCountry && fetchState(byDefaultCountry && byDefaultCountry.id);
    }, [byDefaultCountry]);

    const [checkState, setCheckState] = useState(false);
    const [allState, setAllState] = useState(null);

    useEffect(() => {
        if (countryState.value) {
            setCheckState(true);
            setAllState(countryState);
        }
    }, [settings, countryState]);

    const stateOptions =
        checkState &&
        allState &&
        allState.value &&
        allState.value.map((item) => {
            return {
                id: item,
                name: item,
            };
        });

    const onLanguagesChange = (obj) => {
        setDisable(false);
        setSettingValue((settingValue) => ({
            ...settingValue,
            default_language: obj,
        }));
    };

    const onSmsChange = (obj) => {
        setDisable(false);
        setSettingValue((settingValue) => ({
            ...settingValue,
            sms_gateway: obj,
        }));
    };

    const onCurrencyChange = (obj) => {
        setDisable(false);
        setSettingValue((settingValue) => ({ ...settingValue, currency: obj }));
        setErrors("");
    };

    const onCustomerChange = (obj) => {
        setDisable(false);
        setSettingValue((settingValue) => ({
            ...settingValue,
            default_customer: obj,
        }));
        setErrors("");
    };

    const onWarehouseChange = (obj) => {
        setDisable(false);
        setSettingValue((settingValue) => ({
            ...settingValue,
            default_warehouse: obj,
        }));
        setErrors("");
    };

    const onCountryChange = (obj) => {
        setDisable(false);
        setSettingValue((settingValue) => ({ ...settingValue, country: obj }));
        setSettingValue((settingValue) => ({ ...settingValue, state: null }));
        fetchState(obj.value);
        setErrors("");
    };

    const onStateChange = (obj) => {
        setDisable(false);
        setSettingValue((settingValue) => ({ ...settingValue, state: obj }));
        setErrors("");
    };

    const handleImageChange = (e) => {
        e.preventDefault();
        setDisable(false);
        if (e.target.files.length > 0) {
            const file = e.target.files[0];
            if (file.type === "image/jpeg" || file.type === "image/png") {
                setSelectImg(file);
                const fileReader = new FileReader();
                fileReader.onloadend = () => {
                    setImagePreviewUrl(fileReader.result);
                };
                if (file) {
                    fileReader.readAsDataURL(file);
                }
                setErrors("");
            }
        }
    };

    const handleChanged = (event, checkboxType) => {
        let checked = event.target.checked;
        setDisable(false);
        if (checkboxType === "version") {
            setChecked(checked);
            setSettingValue((settingValue) => ({
                ...settingValue,
                show_version_on_footer: checked,
            }));
        } else if (checkboxType === "logo") {
            setLogoChecked(checked);
            setSettingValue((settingValue) => ({
                ...settingValue,
                show_logo_in_receipt: checked,
            }));
        } else if (checkboxType === "appname") {
            setShowAppName(checked);
            setSettingValue((settingValue) => ({
                ...settingValue,
                show_app_name_in_sidebar: checked,
            }));
        }
    };

    // checkedCurrency
    const [checkedCurrency, setCheckedCurrency] = useState(false);
    const handleChangedCurrency = (event) => {
        let checked = event.target.checked;
        setDisable(false);
        setCheckedCurrency(checked);
        setSettingValue((settingValue) => ({
            ...settingValue,
            Currency_icon_Right_side: checked,
        }));
    };

    const onChangeInput = (event) => {
        event.preventDefault();
        setDisable(false);
        setSettingValue((inputs) => ({
            ...inputs,
            [event.target.name]: event.target.value,
        }));
        setErrors("");
    };

    const prepareFormData = (data) => {
        const formData = new FormData();
        formData.append(
            "currency",
            data.currency.value ? data.currency.value : data.currency
        );
        formData.append("email", data.email);
        if (selectImg) {
            formData.append("logo", data.logo);
        }
        formData.append("company_name", data.company_name);
        formData.append("phone", data.phone);
        formData.append("developed", data.developed);
        formData.append("footer", data.footer);
        if (data.default_language.value) {
            formData.append("default_language", data.default_language.value);
        } else {
            formData.append("default_language", data.default_language);
        }
        formData.append(
            "default_customer",
            data.default_customer.value
                ? data.default_customer.value
                : data.default_customer
        );
        formData.append(
            "default_warehouse",
            data.default_warehouse.value
                ? data.default_warehouse.value
                : data.default_warehouse
        );
        formData.append("address", data.address);
        formData.append("stripe_key", data.stripe_key);
        formData.append("stripe_secret", data.stripe_secret);
        formData.append("sms_gateway", data.sms_gateway);
        formData.append("twillo_sid", data.twillo_sid);
        formData.append("twillo_token", data.twillo_token);
        formData.append("twillo_from", data.twillo_from);
        formData.append("smtp_host", data.smtp_host);
        formData.append("smtp_port", data.smtp_port);
        formData.append("smtp_username", data.smtp_username);
        formData.append("smtp_password", data.smtp_password);
        formData.append("smtp_Encryption", data.smtp_Encryption);
        formData.append(
            "show_version_on_footer",
            data.show_version_on_footer === true ? "1" : "0"
        );
        formData.append(
            "show_logo_in_receipt",
            data.show_logo_in_receipt === true ? "1" : "0"
        );
        formData.append(
            "show_app_name_in_sidebar",
            data.show_app_name_in_sidebar === true ? "1" : "0"
        );
        formData.append("city", data.city);
        formData.append("postcode", data.postCode);
        formData.append("country", data.country.label);
        formData.append("state", data.state.label);
        formData.append("date_format", data.date_format.value);
        formData.append("is_currency_right", data.Currency_icon_Right_side);
        return formData;
    };

    const handleValidation = () => {
        let errorss = {};
        let isValid = false;
        if (!settingValue["currency"]) {
            errorss["currency"] = getFormattedMessage(
                "settings.system-settings.select.default-currency.validate.label"
            );
        } else if (!settingValue["email"]) {
            errorss["email"] = getFormattedMessage(
                "globally.input.email.validate.label"
            );
        } else if (!settingValue["company_name"]) {
            errorss["company_name"] = getFormattedMessage(
                "settings.system-settings.input.company-name.validate.label"
            );
        } else if (!settingValue["phone"]) {
            errorss["phone"] = getFormattedMessage(
                "settings.system-settings.input.company-phone.validate.label"
            );
        } else if (!settingValue["developed"]) {
            errorss["developed"] = getFormattedMessage(
                "settings.system-settings.input.developed-by.validate.label"
            );
        } else if (!settingValue["footer"]) {
            errorss["footer"] = getFormattedMessage(
                "settings.system-settings.input.footer.validate.label"
            );
        } else if (!settingValue["default_language"]) {
            errorss["default_language"] = getFormattedMessage(
                "settings.system-settings.select.default-language.validate.label"
            );
        } else if (!settingValue["default_customer"]) {
            errorss["default_customer"] = getFormattedMessage(
                "settings.system-settings.select.default-customer.validate.label"
            );
        } else if (!settingValue["default_warehouse"]) {
            errorss["default_warehouse"] = getFormattedMessage(
                "settings.system-settings.select.default-warehouse.validate.label"
            );
        } else if (!settingValue["address"]) {
            errorss["address"] = getFormattedMessage(
                "settings.system-settings.select.address.validate.label"
            );
        } else if (
            settingValue["address"] &&
            settingValue["address"].length > 150
        ) {
            errorss["address"] = getFormattedMessage(
                "settings.system-settings.select.address.valid.validate.label"
            );
        } else if (!settingValue["sms_gateway"]) {
            errorss["sms_gateway"] = getFormattedMessage(
                "settings.sms-configuration.select.sms-gateway.validate.label"
            );
        } else if (!settingValue["twillo_sid"]) {
            errorss["twillo_sid"] = getFormattedMessage(
                "settings.sms-configuration.input.twilio-sid.validate.label"
            );
        } else if (!settingValue["twillo_token"]) {
            errorss["twillo_token"] = getFormattedMessage(
                "settings.sms-configuration.input.twilio-token.validate.label"
            );
        } else if (!settingValue["twillo_from"]) {
            errorss["twillo_from"] = getFormattedMessage(
                "settings.sms-configuration.select.twilio-from.validate.label"
            );
        } else if (!settingValue["smtp_host"]) {
            errorss["smtp_host"] = getFormattedMessage(
                "settings.smtp-configuration.input.host.validate.label"
            );
        } else if (!settingValue["smtp_port"]) {
            errorss["smtp_port"] = getFormattedMessage(
                "settings.smtp-configuration.input.port.validate.label"
            );
        } else if (!settingValue["smtp_username"]) {
            errorss["smtp_username"] = getFormattedMessage(
                "settings.smtp-configuration.input.username.validate.label"
            );
        } else if (!settingValue["smtp_password"]) {
            errorss["smtp_password"] = getFormattedMessage(
                "settings.smtp-configuration.input.password.validate.label"
            );
        } else if (!settingValue["smtp_Encryption"]) {
            errorss["smtp_Encryption"] = getFormattedMessage(
                "settings.smtp-configuration.input.encryption.validate.label"
            );
        } else if (!settingValue["city"]) {
            errorss["city"] = getFormattedMessage(
                "settings.system-settings.input.footer.validate.label"
            );
        } else if (!settingValue["postCode"]) {
            errorss["postCode"] = getFormattedMessage(
                "settings.system-settings.select.postcode.validate.label"
            );
        }
        // else if (settingValue['postCode'].length > 8) {
        //     errorss['postCode'] = getFormattedMessage("settings.system-settings.select.postcode.validate.length.label");
        // }
        else if (!settingValue["country"]) {
            errorss["country"] = getFormattedMessage(
                "settings.system-settings.select.country.validate.label"
            );
        } else if (!settingValue["state"]) {
            errorss["state"] = getFormattedMessage(
                "settings.system-settings.select.state.validate.label"
            );
        } else {
            isValid = true;
        }
        setErrors(errorss);
        return isValid;
    };

    const onEdit = (event) => {
        event.preventDefault();
        const valid = handleValidation();
        settingValue.logo = selectImg;
        if (valid) {
            editSetting(prepareFormData(settingValue), true, setDefaultDate);
            setDisable(true);
        }
    };

    const onCacheClear = (event) => {
        event.preventDefault();
        fetchCacheClear();
    };

    const onDateFormatChange = (obj) => {
        setDisable(false);
        setSettingValue((settingValue) => ({
            ...settingValue,
            date_format: obj,
        }));
        setErrors("");
    };

    const scrollToSettingsSection = (sectionId) => {
        document.getElementById(sectionId)?.scrollIntoView({
            behavior: "smooth",
            block: "start",
        });
    };

    return (
        <MasterLayout>
            <TopProgressBar />
            <TabTitle title={placeholderText("settings.title")} />
            <HeaderTitle
                title={getFormattedMessage("settings.system-settings.title")}
            />
            <main className="settings-v2">
                <header className="settings-hero">
                    <div>
                        <span className="settings-eyebrow">Configuración general</span>
                        <h1>Centro de configuración</h1>
                        <p>Personaliza la identidad, operación y preferencias principales de EcuaPos.</p>
                    </div>
                    <div className="settings-hero-badge">
                        <span className="settings-status-dot" />
                        Sistema activo
                    </div>
                </header>

                <nav className="settings-index" aria-label="Secciones de configuración">
                    <button type="button" onClick={() => scrollToSettingsSection("settings-identity")}><i className="bi bi-building" /><span>Identidad</span></button>
                    <button type="button" onClick={() => scrollToSettingsSection("settings-operation")}><i className="bi bi-sliders" /><span>Operación</span></button>
                    <button type="button" onClick={() => scrollToSettingsSection("settings-location")}><i className="bi bi-geo-alt" /><span>Ubicación</span></button>
                    <button type="button" onClick={() => scrollToSettingsSection("settings-interface")}><i className="bi bi-layout-sidebar" /><span>Interfaz</span></button>
                    <button type="button" onClick={() => scrollToSettingsSection("settings-maintenance")}><i className="bi bi-shield-check" /><span>Mantenimiento</span></button>
                </nav>

                <div className="settings-form-card">
                    <div className="settings-form-body">
                        <Form>
                            <div className="row settings-form-grid">
                                <div className="col-12 settings-section-heading" id="settings-identity">
                                    <span className="settings-section-icon"><i className="bi bi-building" /></span>
                                    <div>
                                        <span className="settings-section-step">01 · Identidad y formato</span>
                                        <h2>Información del negocio</h2>
                                        <p>Define cómo se identifica la empresa y cómo se presentan sus valores.</p>
                                    </div>
                                </div>
                                <div className="col-lg-6 mb-3">
                                    <div>
                                        {settings &&
                                            settings.attributes &&
                                            settingValue.currency && (
                                                <ReactSelect
                                                    title={getFormattedMessage(
                                                        "settings.system-settings.select.default-currency.label"
                                                    )}
                                                    placeholder={getFormattedMessage(
                                                        "settings.system-settings.select.default-currency.placeholder.label"
                                                    )}
                                                    value={
                                                        settings
                                                            ? settings.attributes &&
                                                            settingValue.currency
                                                            : ""
                                                    }
                                                    data={currencies}
                                                    onChange={onCurrencyChange}
                                                    errors={errors["currency"]}
                                                />
                                            )}
                                    </div>
                                    <div className="mt-3">
                                        <div>
                                            {getFormattedMessage(
                                                "currency.icon.right.side.lable"
                                            )}
                                        </div>
                                        <div className="d-flex align-items-center mt-2">
                                            <label className="form-check form-switch form-switch-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={
                                                        settingValue.Currency_icon_Right_side
                                                    }
                                                    name="Currency_icon_Right_side"
                                                    onChange={(event) =>
                                                        handleChangedCurrency(
                                                            event
                                                        )
                                                    }
                                                    className="me-3 form-check-input cursor-pointer"
                                                />
                                                <div className="control__indicator" />
                                            </label>
                                            <span
                                                className="switch-slider"
                                                data-checked="✓"
                                                data-unchecked="✕"
                                            >
                                                {errors[
                                                    "Currency_icon_Right_side"
                                                ]
                                                    ? errors[
                                                    "Currency_icon_Right_side"
                                                    ]
                                                    : null}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div className="col-lg-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage(
                                            "settings.system-settings.input.default-email.label"
                                        )}
                                        :
                                    </label>
                                    <input
                                        type="email"
                                        className="form-control"
                                        placeholder={placeholderText(
                                            "settings.system-settings.input.default-email.placeholder.label"
                                        )}
                                        name="email"
                                        value={settingValue.email}
                                        onChange={(e) => onChangeInput(e)}
                                    />
                                    <span className="text-danger d-block fw-400 fs-small mt-2">
                                        {errors["email"]
                                            ? errors["email"]
                                            : null}
                                    </span>
                                </div>
                                <div className="col-lg-6 mb-3">
                                    <ImagePicker
                                        imageTitle={placeholderText(
                                            "globally.input.change-logo.tooltip"
                                        )}
                                        imagePreviewUrl={
                                            imagePreviewUrl
                                                ? imagePreviewUrl
                                                : settings.attributes &&
                                                settings.attributes.logo
                                        }
                                        handleImageChange={handleImageChange}
                                    />
                                </div>
                                <div className="col-lg-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage(
                                            "settings.system-settings.input.company-name.label"
                                        )}
                                        :
                                    </label>
                                    <input
                                        type="text"
                                        className="form-control"
                                        placeholder={placeholderText(
                                            "settings.system-settings.input.company-name.placeholder.label"
                                        )}
                                        name="company_name"
                                        value={settingValue.company_name}
                                        onChange={(e) => onChangeInput(e)}
                                    />
                                    <span className="text-danger d-block fw-400 fs-small mt-2">
                                        {errors["company_name"]
                                            ? errors["company_name"]
                                            : null}
                                    </span>
                                </div>
                                <div className="col-lg-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage(
                                            "settings.system-settings.input.company-phone.label"
                                        )}
                                        :
                                    </label>
                                    <Form.Control
                                        type="number"
                                        className="form-control"
                                        placeholder={placeholderText(
                                            "settings.system-settings.input.company-phone.placeholder.label"
                                        )}
                                        name="phone"
                                        min={0}
                                        value={settingValue.phone}
                                        onKeyPress={(event) =>
                                            numValidate(event)
                                        }
                                        onChange={onChangeInput}
                                    />
                                    <span className="text-danger d-block fw-400 fs-small mt-2">
                                        {errors["phone"]
                                            ? errors["phone"]
                                            : null}
                                    </span>
                                </div>
                                <div className="col-lg-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage(
                                            "settings.system-settings.input.developed-by.label"
                                        )}
                                        :
                                    </label>
                                    <input
                                        type="text"
                                        className="form-control"
                                        placeholder={placeholderText(
                                            "settings.system-settings.input.developed-by.placeholder.label"
                                        )}
                                        name="developed"
                                        value={settingValue.developed}
                                        onChange={(e) => onChangeInput(e)}
                                    />
                                    <span className="text-danger d-block fw-400 fs-small mt-2">
                                        {errors["developed"]
                                            ? errors["developed"]
                                            : null}
                                    </span>
                                </div>
                                <div className="col-lg-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage(
                                            "settings.system-settings.input.footer.label"
                                        )}
                                        :
                                    </label>
                                    <input
                                        type="text"
                                        className="form-control"
                                        placeholder={placeholderText(
                                            "settings.system-settings.input.footer.placeholder.label"
                                        )}
                                        name="footer"
                                        value={settingValue.footer}
                                        onChange={(e) => onChangeInput(e)}
                                    />
                                    <span className="text-danger d-block fw-400 fs-small mt-2">
                                        {errors["footer"]
                                            ? errors["footer"]
                                            : null}
                                    </span>
                                </div>
                                {/*<div className='col-lg-6'>*/}
                                {/*    <ReactSelect title={getFormattedMessage("settings.system-settings.select.default-language.label")} placeholder={placeholderText("settings.system-settings.select.default-language.placeholder.label")} defaultValue={selectedLanguage}*/}
                                {/*                 data={languages} onChange={onLanguagesChange} errors={errors['default_language']}/>*/}
                                {/*</div>*/}
                                <div className="col-12 settings-section-heading" id="settings-operation">
                                    <span className="settings-section-icon"><i className="bi bi-sliders" /></span>
                                    <div>
                                        <span className="settings-section-step">02 · Operación</span>
                                        <h2>Valores predeterminados</h2>
                                        <p>Selecciona el cliente y almacén que agilizan el trabajo diario.</p>
                                    </div>
                                </div>
                                <div className="col-lg-6 mb-3">
                                    {settings &&
                                        settings.attributes &&
                                        settingValue.default_customer && (
                                            <ReactSelect
                                                title={getFormattedMessage(
                                                    "settings.system-settings.select.default-customer.label"
                                                )}
                                                placeholder={getFormattedMessage(
                                                    "settings.system-settings.select.default-customer.placeholder.label"
                                                )}
                                                value={
                                                    settings
                                                        ? settings.attributes &&
                                                        settingValue.default_customer
                                                        : ""
                                                }
                                                data={customers}
                                                onChange={onCustomerChange}
                                                errors={
                                                    errors["default_customer"]
                                                }
                                            />
                                        )}
                                </div>
                                <div className="col-lg-6 mb-3">
                                    {settings &&
                                        settings.attributes &&
                                        settingValue.default_warehouse && (
                                            <ReactSelect
                                                title={getFormattedMessage(
                                                    "settings.system-settings.select.default-warehouse.label"
                                                )}
                                                placeholder={getFormattedMessage(
                                                    "settings.system-settings.select.default-warehouse.label"
                                                )}
                                                value={
                                                    settings
                                                        ? settings.attributes &&
                                                        settingValue.default_warehouse
                                                        : ""
                                                }
                                                data={warehouses}
                                                onChange={onWarehouseChange}
                                                errors={
                                                    errors["default_warehouse"]
                                                }
                                            />
                                        )}
                                </div>

                                <div className="col-12 settings-section-heading" id="settings-location">
                                    <span className="settings-section-icon"><i className="bi bi-geo-alt" /></span>
                                    <div>
                                        <span className="settings-section-step">03 · Ubicación y documentos</span>
                                        <h2>Datos regionales</h2>
                                        <p>Configura la ubicación, dirección y formato de fecha del establecimiento.</p>
                                    </div>
                                </div>
                                {/* Country  */}
                                <div className="col-lg-6 mb-3">
                                    {settings &&
                                        settings.attributes &&
                                        byDefaultCountry && (
                                            <ReactSelect
                                                title={getFormattedMessage(
                                                    "globally.input.country.label"
                                                )}
                                                placeholder={getFormattedMessage(
                                                    "globally.input.country.label"
                                                )}
                                                value={
                                                    settings &&
                                                        settings.attributes &&
                                                        byDefaultCountry
                                                        ? {
                                                            label: settingValue
                                                                .country
                                                                .label,
                                                            value: settingValue
                                                                .country
                                                                .value,
                                                        }
                                                        : ""
                                                }
                                                name="country"
                                                multiLanguageOption={
                                                    defaultCountry.countries
                                                        ? defaultCountry.countries
                                                        : []
                                                }
                                                onChange={onCountryChange}
                                                errors={errors["country"]}
                                            />
                                        )}
                                </div>
                                {/* state  */}
                                <div className="col-lg-6 mb-3">
                                    {settings &&
                                        settings.attributes &&
                                        stateOptions.length && (
                                            <ReactSelect
                                                title={getFormattedMessage(
                                                    "setting.state.lable"
                                                )}
                                                placeholder={getFormattedMessage(
                                                    "setting.state.lable"
                                                )}
                                                name="state"
                                                value={
                                                    settingValue &&
                                                        settingValue.state !== null
                                                        ? settingValue.state
                                                        : ""
                                                }
                                                multiLanguageOption={
                                                    stateOptions
                                                }
                                                onChange={onStateChange}
                                                errors={errors["state"]}
                                            />
                                        )}
                                </div>
                                {/* City  */}
                                <div className="col-lg-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage(
                                            "globally.input.city.label"
                                        )}
                                        :
                                    </label>
                                    <input
                                        type="text"
                                        className="form-control"
                                        placeholder={placeholderText(
                                            "globally.input.city.label"
                                        )}
                                        name="city"
                                        value={settingValue.city}
                                        onChange={(e) => onChangeInput(e)}
                                    />
                                    <span className="text-danger d-block fw-400 fs-small mt-2">
                                        {errors["city"] ? errors["city"] : null}
                                    </span>
                                </div>
                                {/* POST code */}
                                <div className="col-lg-6 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage(
                                            "setting.postCode.lable"
                                        )}
                                        :
                                    </label>
                                    <Form.Control
                                        type="text"
                                        className="form-control"
                                        placeholder={placeholderText(
                                            "setting.postCode.lable"
                                        )}
                                        name="postCode"
                                        min={0}
                                        value={settingValue.postCode}
                                        onKeyPress={(event) => event}
                                        onChange={onChangeInput}
                                    />
                                    <span className="text-danger d-block fw-400 fs-small mt-2">
                                        {/* {errors['postCode'] ? errors['postCode'] : null} */}
                                    </span>
                                </div>
                                <div className="col-lg-6 mb-3">
                                    {settings &&
                                        settings.attributes &&
                                        settings.attributes.date_format &&
                                        defaultDate &&
                                        settingValue.date_format && (
                                            <ReactSelect
                                                title={getFormattedMessage(
                                                    "settings.system-settings.select.date-format.label"
                                                )}
                                                placeholder={getFormattedMessage(
                                                    "settings.system-settings.select.default-warehouse.label"
                                                )}
                                                value={
                                                    settings
                                                        ? settings.attributes &&
                                                        settingValue.date_format
                                                        : ""
                                                }
                                                data={dateFormatOptions}
                                                onChange={onDateFormatChange}
                                                errors={errors["date_format"]}
                                            />
                                        )}
                                </div>
                                <div className="col-12 mb-3">
                                    <label className="form-label">
                                        {getFormattedMessage(
                                            "globally.input.address.label"
                                        )}
                                        :
                                    </label>
                                    <textarea
                                        className="form-control"
                                        rows={3}
                                        placeholder={placeholderText(
                                            "globally.input.address.placeholder.label"
                                        )}
                                        name="address"
                                        value={settingValue.address}
                                        onChange={(e) => onChangeInput(e)}
                                    />
                                    <span className="text-danger d-block fw-400 fs-small mt-2">
                                        {errors["address"]
                                            ? errors["address"]
                                            : null}
                                    </span>
                                </div>
                                <div className="col-12 settings-section-heading" id="settings-interface">
                                    <span className="settings-section-icon"><i className="bi bi-layout-sidebar" /></span>
                                    <div>
                                        <span className="settings-section-step">04 · Interfaz</span>
                                        <h2>Preferencias visuales</h2>
                                        <p>Controla los elementos visibles en comprobantes, menú lateral y pie de página.</p>
                                    </div>
                                </div>
                                <div className="col-lg-6 mb-3">
                                    <div className="col-md-6">
                                        <label className="form-check form-check-custom form-check-solid form-check-inline d-flex align-items-center my-3 cursor-pointer custom-label">
                                            <input
                                                type="checkbox"
                                                name="show_version_on_footer"
                                                value={checked}
                                                checked={checked}
                                                onChange={(event) =>
                                                    handleChanged(
                                                        event,
                                                        "version"
                                                    )
                                                }
                                                className="me-3 form-check-input cursor-pointer"
                                            />
                                            <div className="control__indicator" />{" "}
                                            {getFormattedMessage(
                                                "settings.system-settings.select.default-version-footer.placeholder.label"
                                            )}
                                        </label>
                                    </div>
                                </div>

                                <div className="col-lg-6 mb-3">
                                    <div className="col-md-6">
                                        <label className="form-check form-check-custom form-check-solid form-check-inline d-flex align-items-center my-3 cursor-pointer custom-label">
                                            <input
                                                type="checkbox"
                                                name="show_logo_in_receipt"
                                                value={logoChecked}
                                                checked={logoChecked}
                                                onChange={(event) =>
                                                    handleChanged(event, "logo")
                                                }
                                                className="me-3 form-check-input cursor-pointer"
                                            />
                                            <div className="control__indicator" />{" "}
                                            {getFormattedMessage(
                                                "settings.system-settings.select.logo.placeholder.label"
                                            )}
                                        </label>
                                    </div>
                                </div>

                                {/* show app name inside bar */}
                                <div className="col-lg-6 mb-3">
                                    <div className="col-md-6">
                                        <label className="form-check form-check-custom form-check-solid form-check-inline d-flex align-items-center my-3 cursor-pointer custom-label">
                                            <input
                                                type="checkbox"
                                                name="show_app_name_in_sidebar"
                                                value={showAppName}
                                                checked={showAppName}
                                                onChange={(event) =>
                                                    handleChanged(
                                                        event,
                                                        "appname"
                                                    )
                                                }
                                                className="me-3 form-check-input cursor-pointer"
                                            />
                                            <div className="control__indicator" />{" "}
                                            {getFormattedMessage(
                                                "settings.system-settings.select.appname-sidebar.placeholder.label"
                                            )}
                                        </label>
                                    </div>
                                </div>

                                <div className="col-12 settings-save-bar">
                                    <div className="settings-save-state">
                                        <i className={`bi ${disable ? "bi-check2-circle" : "bi-exclamation-circle"}`} />
                                        <span>{disable ? "Configuración actualizada" : "Tienes cambios pendientes por guardar"}</span>
                                    </div>
                                    <button
                                        disabled={disable}
                                        className="btn btn-primary settings-save-button"
                                        onClick={(event) => onEdit(event)}
                                    >
                                        <i className="bi bi-check2 me-2" />
                                        {getFormattedMessage(
                                            "globally.save-btn"
                                        )}
                                    </button>
                                </div>
                            </div>
                        </Form>
                    </div>
                </div>

                <section className="settings-maintenance" id="settings-maintenance">
                    <div className="settings-maintenance-heading">
                        <span className="settings-eyebrow">Herramientas del sistema</span>
                        <h2>Mantenimiento y respaldo</h2>
                        <p>Acciones administrativas para mantener el sistema actualizado y proteger la información.</p>
                    </div>
                    <div className="settings-maintenance-grid">
                        <article className="settings-tool-card settings-tool-card--cache">
                            <span className="settings-tool-icon"><i className="bi bi-lightning-charge" /></span>
                            <div className="settings-tool-copy">
                                <h3>{getFormattedMessage("settings.clear-cache.title")}</h3>
                                <p>Renueva la caché de la aplicación cuando una configuración no se refleje inmediatamente.</p>
                            </div>
                            <Form>
                                <button
                                    className="btn settings-tool-button"
                                    onClick={(event) => onCacheClear(event)}
                                >
                                    <i className="bi bi-arrow-clockwise me-2" />
                                    {getFormattedMessage(
                                        "settings.clear-cache.title"
                                    )}
                                </button>
                            </Form>
                        </article>
                    </div>
                </section>
            </main>
        </MasterLayout>
    );
};

const mapStateToProps = (state) => {
    const {
        customers,
        warehouses,
        settings,
        currencies,
        countryState,
        dateFormat,
        defaultCountry,
    } = state;
    return {
        customers,
        warehouses,
        settings,
        currencies,
        countryState,
        dateFormat,
        defaultCountry,
    };
};

export default connect(mapStateToProps, {
    fetchSetting,
    fetchCurrencies,
    fetchCacheClear,
    fetchAllCustomer,
    fetchAllWarehouses,
    editSetting,
    fetchState,
})(Settings);
