<?php

use App\Models\SaaSPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $private = Storage::disk('saas_private');
        $public = Storage::disk('public');

        SaaSPayment::query()
            ->whereNotNull('proof_path')
            ->select(['id', 'proof_path'])
            ->orderBy('id')
            ->chunkById(100, function ($payments) use ($private, $public): void {
                foreach ($payments as $payment) {
                    $path = $payment->proof_path;

                    if ($private->exists($path) || ! $public->exists($path)) {
                        continue;
                    }

                    $private->put($path, $public->get($path));

                    // Solo retiramos la copia pública después de comprobar
                    // que el archivo privado quedó escrito correctamente.
                    if ($private->exists($path)) {
                        $public->delete($path);
                    }
                }
            });
    }

    public function down(): void
    {
        $private = Storage::disk('saas_private');
        $public = Storage::disk('public');

        SaaSPayment::query()
            ->whereNotNull('proof_path')
            ->select(['id', 'proof_path'])
            ->orderBy('id')
            ->chunkById(100, function ($payments) use ($private, $public): void {
                foreach ($payments as $payment) {
                    $path = $payment->proof_path;

                    if ($public->exists($path) || ! $private->exists($path)) {
                        continue;
                    }

                    $public->put($path, $private->get($path));
                }
            });
    }
};
