<?php

namespace App\Console\Commands;

use App\Services\SaaS\BillingService;
use Illuminate\Console\Command;

class ReconcileSaaSSubscriptions extends Command
{
    protected $signature = 'saas:reconcile-subscriptions';
    protected $description = 'Actualiza pruebas, renovaciones pendientes, períodos de gracia y cancelaciones SaaS';

    public function handle(BillingService $billing): int
    {
        $this->components->info('Suscripciones conciliadas: '.json_encode($billing->reconcileDueSubscriptions()));
        return self::SUCCESS;
    }
}
