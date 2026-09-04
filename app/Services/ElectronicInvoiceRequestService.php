<?php

namespace App\Services;

use App\Jobs\EmitirFacturaJob;
use App\Models\ElectronicInvoice;
use App\Models\Sale;
use App\Services\SaaS\EntitlementService;
use Illuminate\Support\Facades\Log;

class ElectronicInvoiceRequestService
{
    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    public function request(Sale $sale, ?string $documentType): bool
    {
        if ($documentType !== ElectronicInvoice::FACTURA) {
            return false;
        }

        $claimed = Sale::whereKey($sale->id)
            ->whereNull('electronic_invoice_requested_at')
            ->update([
                'electronic_invoice_requested_type' => $documentType,
                'electronic_invoice_requested_at' => now(),
            ]);

        if (! $claimed) {
            return false;
        }

        $sale->loadMissing('warehouse.store');
        $organizationId = (int) $sale->warehouse?->store?->organization_id;
        $reserved = false;

        try {
            $reserved = $this->entitlements->reserveElectronicDocument(
                $organizationId,
                EntitlementService::SOURCE_SALE_INVOICE,
                $sale->id
            );
            EmitirFacturaJob::dispatch($sale->id, $documentType)->afterCommit();
        } catch (\Throwable $exception) {
            Sale::whereKey($sale->id)->update([
                'electronic_invoice_requested_type' => null,
                'electronic_invoice_requested_at' => null,
            ]);
            if ($reserved) {
                $this->entitlements->releaseElectronicDocument(
                    EntitlementService::SOURCE_SALE_INVOICE,
                    $sale->id
                );
            }
            Log::error("No se pudo encolar la factura de la venta {$sale->id}: {$exception->getMessage()}");

            throw $exception;
        }

        return true;
    }
}
