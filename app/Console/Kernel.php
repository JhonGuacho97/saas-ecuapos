<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('saas:reconcile-subscriptions')->hourly()->withoutOverlapping();

        // Los tokens de Sanctum se emiten con vencimiento a 2 horas
        // (ver AuthController::login), pero Laravel no borra solo las
        // filas vencidas: sin esto, personal_access_tokens crece sin
        // techo con credenciales muertas -- ruido inútil en cualquier
        // respaldo de la base y en una auditoría.
        $schedule->command('sanctum:prune-expired --hours=24')->daily();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
