<?php

namespace App\Jobs;

use App\Mail\MailSender;
use App\Models\ElectronicInvoice;
use App\Models\MailTemplate;
use App\Services\SriConfigService;
use App\Services\SriRideService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendRideEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $electronicInvoiceId
    ) {
    }

    public function handle(SriRideService $rideService): void
    {
        $factura = ElectronicInvoice::with('sale.customer')->find($this->electronicInvoiceId);

        if (!$factura) {
            Log::warning("SendRideEmailJob: factura {$this->electronicInvoiceId} no encontrada.");
            return;
        }

        $customer = $factura->sale?->customer;

        if (!$customer || empty($customer->email)) {
            Log::info("SendRideEmailJob: factura {$factura->id} sin cliente o sin correo registrado, no se envía.");
            return;
        }

        $template = MailTemplate::effectiveForStore($factura->store_id, MailTemplate::MAIL_TYPE_ELECTRONIC_INVOICE);

        if (empty($template) || $template->status != MailTemplate::ACTIVE) {
            Log::info("SendRideEmailJob: no hay una plantilla de Documentos Electrónicos activa, no se envía.");
            return;
        }

        $cfg = SriConfigService::get($factura->store_id);

        $tipoDocumentoLabel = [
            ElectronicInvoice::FACTURA => 'Factura',
            ElectronicInvoice::NOTA_CREDITO => 'Nota de Crédito',
            ElectronicInvoice::NOTA_DEBITO => 'Nota de Débito',
        ][$factura->tipo_comprobante] ?? 'Comprobante';

        $search = [
            '{{NUMERO DE COMPROBANTE}}',
            '{{NOMBRE DE LA EMPRESA}}',
            '{{FECHA EMISION}}',
            '{{TIPO DOCUMENTO}}',
            '{{NUMERO DOCUMENTO}}',
        ];

        $replace = [
            $factura->numeroComprobante(),
            $cfg['nombre_comercial'] ?: $cfg['razon_social'],
            optional($factura->fecha_autorizacion)->format('d/m/Y') ?: now()->format('d/m/Y'),
            $tipoDocumentoLabel,
            $factura->numeroComprobante(),
        ];

        $subject = str_replace(
            $search,
            $replace,
            $template->subject ?: 'Documento Electrónico: {{NUMERO DE COMPROBANTE}}'
        );
        $content = str_replace($search, $replace, $template->content);

        // Adjuntos: el RIDE en PDF y el XML autorizado por el SRI.
        // Si alguno falla en generarse, el correo se manda igual con lo
        // que sí se pudo armar -- mejor que no llegue nada.
        $attachments = [];

        try {
            $ridePath = $factura->tipo_comprobante === ElectronicInvoice::NOTA_CREDITO
                ? $rideService->guardarYObtenerRutaNotaCredito($factura)
                : $rideService->guardarYObtenerRuta($factura);
            $attachments[] = [
                'path' => $ridePath,
                'name' => $factura->numeroComprobante() . '.pdf',
                'mime' => 'application/pdf',
            ];
        } catch (\Throwable $e) {
            Log::warning("SendRideEmailJob: no se pudo generar el RIDE de la factura {$factura->id}: " . $e->getMessage());
        }

        $xmlTmpPath = null;
        if (!empty($factura->xml_autorizado)) {
            $xmlTmpPath = tempnam(sys_get_temp_dir(), 'ride_xml_');
            file_put_contents($xmlTmpPath, $factura->xml_autorizado);
            $attachments[] = [
                'path' => $xmlTmpPath,
                'name' => $factura->numeroComprobante() . '.xml',
                'mime' => 'application/xml',
            ];
        }

        try {
            Mail::to($customer->email)->send(
                new MailSender('emails.mail-sender', $subject, ['data' => $content], $attachments)
            );
            $factura->update(['correo_enviado_at' => now()]);
            Log::info("SendRideEmailJob: correo enviado a {$customer->email} para la factura {$factura->id}.");
        } catch (\Throwable $e) {
            Log::error("SendRideEmailJob: falló el envío para la factura {$factura->id}: " . $e->getMessage());
        } finally {
            if ($xmlTmpPath && file_exists($xmlTmpPath)) {
                @unlink($xmlTmpPath);
            }
        }
    }
}
