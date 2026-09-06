<?php

return [
    /*
     * proveedor => clase que implementa RecurringPaymentGateway.
     * Se deja vacío hasta seleccionar y configurar la cuenta comercial.
     */
    'payment_gateways' => [],

    // Una autorización confirmada por el servidor puede sostener el POS
    // sin conexión por este tiempo, nunca más allá del vencimiento real.
    'offline_lease_hours' => (int) env('SAAS_OFFLINE_LEASE_HOURS', 12),

    // Una renovacion manual del mismo plan solo puede solicitarse cuando
    // falten estos dias (o menos) para terminar el periodo ya pagado.
    'renewal_window_days' => (int) env('SAAS_RENEWAL_WINDOW_DAYS', 5),
];
