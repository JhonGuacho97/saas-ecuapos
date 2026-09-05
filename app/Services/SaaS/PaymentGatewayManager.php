<?php

namespace App\Services\SaaS;

use InvalidArgumentException;

class PaymentGatewayManager
{
    public function driver(string $provider): RecurringPaymentGateway
    {
        $class = config("saas.payment_gateways.{$provider}");
        if (! $class) {
            throw new InvalidArgumentException("La pasarela {$provider} no está configurada.");
        }

        $gateway = app($class);
        if (! $gateway instanceof RecurringPaymentGateway) {
            throw new InvalidArgumentException("La pasarela {$provider} no implementa el contrato de cobros recurrentes.");
        }

        return $gateway;
    }
}
