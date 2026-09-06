<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('saas_plans')->where('code', 'trial')->update([
            'name' => 'Prueba de 14 días',
            'trial_days' => 14,
            'updated_at' => now(),
        ]);

        $trialPlanId = DB::table('saas_plans')->where('code', 'trial')->value('id');
        if ($trialPlanId) {
            DB::table('organization_subscriptions')
                ->where('saas_plan_id', $trialPlanId)
                ->whereIn('status', ['TRIALING', 'EXPIRED'])
                ->orderBy('id')
                ->get(['id', 'starts_at', 'trial_ends_at'])
                ->each(function ($subscription) {
                    $expectedEnd = Carbon::parse($subscription->starts_at)->addDays(14);
                    if (! $subscription->trial_ends_at || Carbon::parse($subscription->trial_ends_at)->lessThan($expectedEnd)) {
                        $update = [
                            'trial_ends_at' => $expectedEnd,
                            'updated_at' => now(),
                        ];
                        if ($expectedEnd->isFuture()) {
                            $update['status'] = 'TRIALING';
                        }
                        DB::table('organization_subscriptions')->where('id', $subscription->id)->update($update);
                    }
                });
        }
    }

    public function down(): void
    {
        // El período comercial anterior no se restaura para no acortar
        // pruebas que ya hayan sido concedidas.
    }
};
