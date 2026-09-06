export const formatMoney = (value, currency = 'USD') => new Intl.NumberFormat('es-EC', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
}).format(Number(value || 0));

export const formatDate = value => value
    ? new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium' }).format(new Date(value))
    : '—';

export const subscriptionStatusLabels = {
    ACTIVE: 'Activa',
    TRIALING: 'Prueba',
    PAST_DUE: 'Pago pendiente',
    EXPIRED: 'Vencida',
    SUSPENDED: 'Suspendida',
    CANCELED: 'Cancelada',
    PAID: 'Pagado',
    PENDING: 'Pendiente',
    FAILED: 'Fallido',
    REFUNDED: 'Reembolsado',
};
