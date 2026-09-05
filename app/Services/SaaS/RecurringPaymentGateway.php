<?php

namespace App\Services\SaaS;

use App\Models\OrganizationSubscription;

/**
 * Contrato que debe implementar la pasarela elegida. EcuaPos nunca recibe
 * números de tarjeta: solo el identificador seguro de la suscripción creado
 * por el proveedor y el resultado firmado del cobro.
 */
interface RecurringPaymentGateway
{
    /**
     * @return array{reference:string, amount:float, currency:string, paid_at?:string, metadata?:array}
     */
    public function charge(OrganizationSubscription $subscription): array;
}
