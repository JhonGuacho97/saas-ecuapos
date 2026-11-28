import { useState } from "react";
import { useDispatch } from "react-redux";
import { useNavigate } from "react-router-dom";
import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc';
import localizedFormat from 'dayjs/plugin/localizedFormat';
import isoWeek from 'dayjs/plugin/isoWeek';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(utc);
dayjs.extend(localizedFormat);
dayjs.extend(isoWeek);
dayjs.extend(relativeTime);
import { addToast } from "../store/action/toastAction";
import { Tokens } from "../constants";
import { validarIdentificacion } from "../utils/identificacionValidator";

export const useCustomerForm = ({ singleCustomer, addCustomerData, editCustomer, id, initialValues = null }) => {
    const navigate = useNavigate();
    const dispatch = useDispatch();

    const customerData = singleCustomer ? singleCustomer[0] : null;

    const [customerValue, setCustomerValue] = useState({
        tipo_identificacion: customerData?.tipo_identificacion || "05",
        identification: customerData?.identification || "",
        name: customerData?.name || "",
        dob: customerData?.dob ? dayjs(customerData.dob).toDate() : null,
        email: customerData?.email || "",
        phone: customerData?.phone || "",
        country: customerData?.country || "",
        city: customerData?.city || "",
        address: customerData?.address || "",
        credit_enabled: Boolean(customerData?.credit_enabled),
        credit_limit: customerData?.credit_limit ?? "0.00",
        default_payment_terms_days: customerData?.default_payment_terms_days ?? 0,
        ...(customerData ? {} : initialValues || {}),
    });

    const [errors, setErrors] = useState({});
    const [sriLoading, setSriLoading] = useState(false);

    const isDisabled =
        customerData &&
        (customerData.identification || "") === (customerValue.identification || "") &&
        customerData.name === customerValue.name &&
        customerData.email === customerValue.email &&
        customerData.phone === customerValue.phone &&
        customerData.country === customerValue.country &&
        customerData.city === customerValue.city &&
        customerData.address === customerValue.address &&
        Boolean(customerData.credit_enabled) === Boolean(customerValue.credit_enabled) &&
        Number(customerData.credit_limit || 0) === Number(customerValue.credit_limit || 0) &&
        Number(customerData.default_payment_terms_days || 0) === Number(customerValue.default_payment_terms_days || 0);

    const handleValidation = () => {
        const e = {};

        if (!customerValue.name) e.name = "El nombre es obligatorio";
        if (!customerValue.country) e.country = "El país es obligatorio";
        if (!customerValue.city) e.city = "La ciudad es obligatoria";
        if (!customerValue.address) e.address = "La dirección es obligatoria";
        if (customerValue.credit_enabled && Number(customerValue.credit_limit) < 0) e.credit_limit = "El cupo no puede ser negativo";
        if (customerValue.credit_enabled && Number(customerValue.default_payment_terms_days) < 0) e.default_payment_terms_days = "El plazo no puede ser negativo";

        const errorId = validarIdentificacion(
            customerValue.tipo_identificacion,
            customerValue.identification
        );
        if (errorId) e.identification = errorId;

        setErrors(e);
        return Object.keys(e).length === 0;
    };

    const onChangeInput = (e) => {
        const { name, value, type, checked } = e.target;
        const nextValue = type === "checkbox" ? checked : value;
        let newValues = { ...customerValue, [name]: nextValue };

        if (name === "tipo_identificacion" && value === "07") {
            newValues.identification = "";
            newValues.name = "Consumidor Final";
        }

        // Validar identificación en tiempo real
        if (name === "identification" || name === "tipo_identificacion") {
            const tipo = name === "tipo_identificacion" ? value : customerValue.tipo_identificacion;
            const valor = name === "identification" ? value : customerValue.identification;
            setErrors((prev) => ({ ...prev, identification: validarIdentificacion(tipo, valor) }));
        } else {
            setErrors((prev) => ({ ...prev, [name]: null }));
        }

        setCustomerValue(newValues);
    };

    const onSubmit = async (event) => {
        event.preventDefault();
        if (!handleValidation()) return;

        if (customerData) {
            if (!isDisabled) editCustomer(id, customerValue, navigate);
        } else {
            addCustomerData(customerValue);
        }
    };

const handleSriLookup = async () => {
    const identification = customerValue.identification || "";
    if (identification.length < 10) return;

    setSriLoading(true);
    try {
        // La consulta exige sesión: gasta el token de ApiConsult y devuelve
        // datos personales, así que no puede quedar abierta al público.
        const headers = { Accept: "application/json" };
        const token = localStorage.getItem(Tokens.ADMIN);
        const storeId = localStorage.getItem(Tokens.CURRENT_STORE_ID);
        if (token) headers.Authorization = `Bearer ${token}`;
        if (storeId) headers["X-Store-Id"] = storeId;
        const res = await fetch(
            `/api/sri/lookup?identification=${encodeURIComponent(identification)}`,
            { headers }
        );

        let json;
        try {
            json = await res.json();
        } catch {
            console.error("Respuesta no es JSON válido. Status:", res.status);
            dispatch(addToast({ text: `Error del servidor (status ${res.status})`, type: "error" }));
            return;
        }

        if (!res.ok) {
            console.error("Error SRI lookup:", json);
            dispatch(addToast({ text: json.debug_message || json.message || "No encontrado en el SRI", type: "error" }));
            return;
        }

        setCustomerValue((prev) => ({
            ...prev,
            name: json.name || prev.name,
            email: json.email || prev.email,
            phone: json.phone || prev.phone,
            address: json.address || prev.address,
            city: json.city || prev.city,
            country: json.country || prev.country,
        }));

        dispatch(addToast({ text: "Datos cargados desde el SRI" }));
    } catch (err) {
        console.error("Error de red en SRI lookup:", err);
        dispatch(addToast({ text: "Error de red", type: "error" }));
    } finally {
        setSriLoading(false);
    }
};

    return {
        customerData,
        customerValue,
        errors,
        sriLoading,
        isDisabled,
        onChangeInput,
        onSubmit,
        handleSriLookup,
    };
};
