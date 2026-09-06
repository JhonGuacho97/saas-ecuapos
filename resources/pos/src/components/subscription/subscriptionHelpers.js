export const money = (value, currency = 'USD') => new Intl.NumberFormat('es-EC', {
    style: 'currency', currency: currency || 'USD', minimumFractionDigits: 2,
}).format(Number(value || 0));

export const interval = plan => ({
    daily: 'día', weekly: 'semana', yearly: 'año',
}[plan?.billing_interval] || 'mes');

export const shortDate = value => (value
    ? new Date(value).toLocaleDateString('es-EC', { day: '2-digit', month: 'short', year: 'numeric' })
    : '—');

/**
 * Un límite nulo en el plan significa "sin tope". Se arma la frase
 * completa acá para no repetir el mismo ternario en cada tarjeta.
 */
export const planFeatures = plan => [
    [plan?.max_users, 'usuarios'],
    [plan?.max_stores, 'tiendas'],
    [plan?.max_warehouses, 'almacenes'],
    [plan?.max_electronic_documents, 'documentos electrónicos'],
].map(([limit, noun]) => (limit ? `${limit} ${noun}` : `${noun.charAt(0).toUpperCase()}${noun.slice(1)} ilimitados`));

export const statusLabel = status => ({
    ACTIVE: 'Activa',
    TRIALING: 'En prueba',
    PAST_DUE: 'Pago pendiente',
    EXPIRED: 'Vencida',
    CANCELED: 'Cancelada',
    MISSING: 'Sin suscripción',
}[status] || status || '—');
