<?php
namespace App\Services;
use App\Models\ElectronicInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Services\SriConfigService;
use Illuminate\Support\Facades\Storage;

class SriRideService
{
    /**
     * Los RIDE llevan nombre y cédula del cliente, montos y RUC del
     * emisor. Estaban en el disco 'local' (root public_path('uploads')),
     * o sea servidos por HTTP en /uploads/rides/factura_<clave>.pdf: la
     * clave de acceso es un número de 49 dígitos con estructura conocida
     * (fecha + RUC + secuencial), así que un tercero con el RUC del
     * negocio podía enumerarlas y bajarse su facturación completa.
     *
     * Nada del frontend enlaza estos archivos directo -- la descarga va
     * por /api/electronic-invoices/{id}/ride, autenticada -- así que
     * moverlos al disco privado no rompe ninguna ruta pública.
     */
    private const RIDE_DISK = 'saas_private';

    private const FORMAS_PAGO_TEXTO = [
        '01' => 'EFECTIVO',
        '15' => 'TRANSFERENCIA / COMPENSACIÓN',
        '16' => 'TARJETA DE DÉBITO',
        '17' => 'DINERO ELECTRÓNICO',
        '19' => 'TARJETA DE CRÉDITO',
        '20' => 'OTROS',
    ];

    public function generarPdf(ElectronicInvoice $factura): string
    {
        $venta = $factura->sale()->with(['customer', 'saleItems.product'])->first();

        // Generar QR con la clave de acceso (formato que reconoce el lector del SRI)
        $qrSvg = QrCode::format('svg')
            ->size(200)
            ->generate($factura->clave_acceso);
        $qrBase64 = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

        $formaPagoTexto = self::FORMAS_PAGO_TEXTO[$venta->formaPagoSri()] ?? 'OTROS';

        $sriConfig = SriConfigService::get($factura->store_id);
        $logoBase64 = $this->obtenerLogoBase64($sriConfig, $factura->store_id);

        $pdf = Pdf::loadView('sri.ride', [
            'factura' => $factura,
            'venta' => $venta,
            'sri' => $sriConfig,
            'qrBase64' => $qrBase64,
            'logoBase64' => $logoBase64,
            'formaPagoTexto' => $formaPagoTexto,
        ])->setPaper('a4', 'portrait')->setOption([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);

        return $pdf->output();
    }

    public function guardarYObtenerRuta(ElectronicInvoice $factura): string
    {
        $pdfContent = $this->generarPdf($factura);
        $nombreArchivo = "rides/factura_{$factura->clave_acceso}.pdf";
        Storage::disk(self::RIDE_DISK)->put($nombreArchivo, $pdfContent);

        // Ruta absoluta real, no la ruta relativa al disco -- para que
        // cualquiera que reciba esto (como el adjunto de un correo)
        // pueda usarla directo con file_exists()/attach() sin tener que
        // saber en qué disco de Storage vive.
        return Storage::disk(self::RIDE_DISK)->path($nombreArchivo);
    }

    /**
     * RIDE de Nota de Crédito -- misma idea que generarPdf() pero
     * partiendo de CreditNote en vez de Sale, con su propia plantilla
     * (referencia al documento que corrige, motivo, sin datos de pago).
     */
    public function generarPdfNotaCredito(ElectronicInvoice $comprobante): string
    {
        $creditNote = $comprobante->creditNote()
            ->with(['customer', 'creditNoteItems.product'])
            ->first();

        $qrSvg = QrCode::format('svg')
            ->size(200)
            ->generate($comprobante->clave_acceso);
        $qrBase64 = 'data:image/svg+xml;base64,' . base64_encode($qrSvg);

        $sriConfig = SriConfigService::get($comprobante->store_id);
        $logoBase64 = $this->obtenerLogoBase64($sriConfig, $comprobante->store_id);

        $conceptoTexto = match ($creditNote->concepto) {
            'POR_DEVOLUCION' => 'Por Devolución',
            'POR_DESCUENTO' => 'Por Descuento',
            'POR_CORRECCION_PRECIO' => 'Por Corrección de Precio',
            'POR_ERROR_FACTURACION' => 'Por Error de Facturación',
            default => 'Otro',
        };

        $pdf = Pdf::loadView('sri.ride-nota-credito', [
            'comprobante' => $comprobante,
            'creditNote' => $creditNote,
            'sri' => $sriConfig,
            'qrBase64' => $qrBase64,
            'logoBase64' => $logoBase64,
            'conceptoTexto' => $conceptoTexto,
        ])->setPaper('a4', 'portrait')->setOption([
            'tempDir' => public_path(),
            'chroot' => public_path(),
        ]);

        return $pdf->output();
    }

    public function guardarYObtenerRutaNotaCredito(ElectronicInvoice $comprobante): string
    {
        $pdfContent = $this->generarPdfNotaCredito($comprobante);
        $nombreArchivo = "rides/nota_credito_{$comprobante->clave_acceso}.pdf";
        Storage::disk(self::RIDE_DISK)->put($nombreArchivo, $pdfContent);

        return Storage::disk(self::RIDE_DISK)->path($nombreArchivo);
    }

    /**
     * Prefiere el logo subido en la propia Configuración SRI
     * (sri_logo, pensado específicamente para el RIDE) sobre el logo
     * general de la app (getLogoUrl(), pensado para sidebar/login/
     * emails) -- así el RIDE no depende de que alguien haya subido el
     * logo "de branding general" sin saber que también alimentaba el
     * comprobante fiscal.
     */
    private function obtenerLogoBase64(array $sriConfig, ?int $storeId = null): string
    {
        $logoUrl = $sriConfig['logo_url'] ?? '';
        if (empty($logoUrl)) {
            $logoUrl = getLogoUrl($storeId);
        }
        $urlPath = parse_url($logoUrl, PHP_URL_PATH);
        $urlPath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $urlPath), DIRECTORY_SEPARATOR);
        $logoPath = public_path($urlPath);

        if (!file_exists($logoPath)) {
            return '';
        }

        $logoMime = mime_content_type($logoPath);
        $logoData = base64_encode(file_get_contents($logoPath));

        return "data:{$logoMime};base64,{$logoData}";
    }
}