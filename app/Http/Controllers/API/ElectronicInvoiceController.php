<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Jobs\EmitirFacturaJob;
use App\Jobs\ReenviarComprobanteJob;
use App\Models\ElectronicInvoice;
use App\Models\Sale;
use App\Services\SriRideService;
use App\Services\ElectronicInvoiceRequestService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ElectronicInvoiceController extends AppBaseController
{
    /**
     * Dispara la emisión de la factura electrónica para una venta.
     * No bloquea — encola el job y responde inmediatamente.
     */
    public function emitir(Sale $sale, ElectronicInvoiceRequestService $invoiceRequests): JsonResponse
    {
        $this->authorizeWarehouseAccess($sale->warehouse_id);
        if ($sale->electronicInvoice) {
            return response()->json([
                'success' => false,
                'message' => 'Esta venta ya tiene una factura electrónica generada.',
                'data' => $sale->electronicInvoice,
            ], 409);
        }

        if ($sale->electronic_invoice_requested_at) {
            return response()->json([
                'success' => true,
                'message' => 'La factura electrónica ya está en la cola de procesamiento.',
            ], 202);
        }

        $invoiceRequests->request($sale, ElectronicInvoice::FACTURA);

        return response()->json([
            'success' => true,
            'message' => 'La factura electrónica se está procesando.',
        ], 202);
    }

    /**
     * Consulta el estado actual de la factura electrónica de una venta.
     * El frontend hace polling a este endpoint cada pocos segundos
     * mientras el estado sea PENDIENTE o RECIBIDA.
     */
    public function estado(Sale $sale): JsonResponse
    {
        $this->authorizeWarehouseAccess($sale->warehouse_id);
        $factura = $sale->electronicInvoice()->first();

        if (!$factura) {
            return response()->json([
                'success' => true,
                'data' => [
                    'estado' => 'PROCESANDO',
                    'esta_pendiente' => true,
                    'esta_autorizada' => false,
                    'esta_rechazada' => false,
                    'puede_reintentar' => false,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $factura->id,
                'estado' => $factura->estado,
                'clave_acceso' => $factura->clave_acceso,
                'numero_autorizacion' => $factura->numero_autorizacion,
                'numero_comprobante' => $factura->numeroComprobante(),
                'fecha_autorizacion' => $factura->fecha_autorizacion,
                'intentos' => $factura->intentos,
                'mensajes' => $factura->mensajes_sri,
                'esta_autorizada' => $factura->estaAutorizada(),
                'esta_pendiente' => $factura->estaPendiente(),
                'esta_rechazada' => $factura->estaRechazada(),
                'puede_reintentar' => $factura->puedeReintentarse(),
            ],
        ]);
    }

    /**
     * Reintenta manualmente la emisión de una factura DEVUELTA o NO_AUTORIZADA.
     * Genera todo desde cero (nueva clave de acceso, nuevo secuencial) --
     * el registro fallido YA NO se borra (antes se borraba acá, lo cual
     * rompía la protección anti-loop de proximoSecuencial(), que necesita
     * ver los secuenciales ya rechazados para no volver a calcular el
     * mismo número que el SRI ya conoce y rechazaría de nuevo).
     */
    public function reintentar(Sale $sale): JsonResponse
    {
        $this->authorizeWarehouseAccess($sale->warehouse_id);
        $factura = $sale->electronicInvoice;

        if (!$factura) {
            return response()->json([
                'success' => false,
                'message' => 'Esta venta no tiene factura electrónica previa.',
            ], 404);
        }

        if ($factura->estaAutorizada()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta factura ya está autorizada, no se puede reintentar.',
            ], 409);
        }

        // Error temporal del SRI: reenviamos el MISMO comprobante ya
        // firmado, sin borrar ni generar un secuencial nuevo -- ver el
        // porqué en ReenviarComprobanteJob.
        if ($factura->esErrorTemporalSri()) {
            ReenviarComprobanteJob::dispatch($factura->id);

            return response()->json([
                'success' => true,
                'message' => 'Reintentando el envío del comprobante (era un error temporal del SRI, no de tu factura).',
            ], 202);
        }

        EmitirFacturaJob::dispatch($sale->id);

        return response()->json([
            'success' => true,
            'message' => 'Reintentando la emisión de la factura electrónica.',
        ], 202);
    }

    /**
     * Lista todas las facturas electrónicas con filtros opcionales.
     * Útil para una pantalla de "Comprobantes electrónicos" en el admin.
     */
    public function index(Request $request): JsonResponse
    {
        $storeId = $this->requireCurrentStoreId();
        $query = ElectronicInvoice::with(['sale.customer'])
            ->where('store_id', $storeId)
            ->orderBy('created_at', 'desc');

        if ($request->filled('estado') && $request->input('estado') !== 'TODOS') {
            $query->where('estado', $request->input('estado'));
        }

        if ($request->filled('tipo_comprobante') && $request->input('tipo_comprobante') !== 'TODOS') {
            $query->where('tipo_comprobante', $request->input('tipo_comprobante'));
        }

        // El rango lo elige el usuario en su calendario local (Ecuador),
        // pero created_at se guarda en UTC real -- convertir antes de
        // comparar, si no el filtro queda corrido hasta 5 horas.
        if ($request->filled('fecha_desde')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('fecha_desde'), 'America/Guayaquil')->startOfDay()->utc());
        }

        if ($request->filled('fecha_hasta')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('fecha_hasta'), 'America/Guayaquil')->endOfDay()->utc());
        }

        if ($request->filled('customer_id')) {
            $customerId = $request->input('customer_id');
            $query->whereHas('sale', function ($q) use ($customerId) {
                $q->where('customer_id', $customerId);
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            // El número de comprobante completo (001-001-000000002) no es
            // una columna real -- se arma con estab/pto_emi (globales) +
            // secuencial. Si el texto trae guiones, el secuencial es el
            // último segmento; si no trae guiones, es el texto completo
            // (solo dígitos). Concatenar los tres grupos sin distinguirlos
            // (como se hacía antes) nunca iba a calzar con la columna
            // `secuencial`, que solo guarda los 9 dígitos finales.
            $segmentos = explode('-', $search);
            $ultimoSegmento = end($segmentos);
            $secuencialBuscado = preg_replace('/\D/', '', $ultimoSegmento);

            $query->where(function ($q) use ($search, $secuencialBuscado) {
                $q->where('clave_acceso', 'like', "%{$search}%")
                    ->orWhereHas('sale', function ($q2) use ($search) {
                        $q2->where('reference_code', 'like', "%{$search}%")
                            ->orWhereHas('customer', function ($q3) use ($search) {
                                $q3->where('name', 'like', "%{$search}%");
                            });
                    });

                if ($secuencialBuscado !== '') {
                    $q->orWhere('secuencial', 'like', "%{$secuencialBuscado}%");
                }
            });
        }

        $perPage = $request->input('per_page', 15);
        $paginated = $query->paginate($perPage);

        // Forma plana pensada para la tabla del módulo de Documentos
        // Electrónicos -- evita que el frontend tenga que andar
        // navegando sale.customer.name, sale.grand_total, etc.
        $paginated->through(function (ElectronicInvoice $item) {
            $sale = $item->sale;

            return [
                'id' => $item->id,
                'sale_id' => $item->sale_id,
                'fecha' => optional($item->created_at)->format('Y-m-d H:i:s'),
                'numero_comprobante' => $item->numeroComprobante(),
                'tipo_comprobante' => $item->tipo_comprobante,
                'reference_code' => $sale?->reference_code,
                'cliente' => $sale?->customer?->name,
                'estado' => $item->estado,
                'firmado' => ! empty($item->xml_firmado),
                'clave_acceso' => $item->clave_acceso,
                'numero_autorizacion' => $item->numero_autorizacion,
                'intentos' => $item->intentos,
                'total' => (float) ($sale?->grand_total ?? 0),
                'saldo' => (float) (($sale?->grand_total ?? 0) - ($sale?->paid_amount ?? 0)),
                'puede_reintentar' => $item->puedeReintentarse(),
                'mensajes' => $item->mensajes_sri,
                'conflicto_secuencial' => $item->tieneConflictoSecuencial(),
                'ambiente' => $item->ambiente,
                'estab' => $item->estab,
                'pto_emi' => $item->pto_emi,
            ];
        });

        // No autorizados = todo lo que no llegó a un estado final exitoso
        // (pendiente, en camino, rechazado o devuelto por el SRI).
        $noAutorizados = ElectronicInvoice::whereIn('estado', [
            ElectronicInvoice::PENDIENTE,
            ElectronicInvoice::RECIBIDA,
            ElectronicInvoice::NO_AUTORIZADA,
            ElectronicInvoice::DEVUELTA,
            ElectronicInvoice::ERROR_TEMPORAL_SRI,
        ])->when($storeId, function ($q, $storeId) {
            $q->where('store_id', $storeId);
        })->count();

        $saldoTotal = (float) Sale::whereHas('electronicInvoice', function ($q) use ($storeId) {
            if ($storeId) {
                $q->where('store_id', $storeId);
            }
        })
            ->selectRaw('COALESCE(SUM(grand_total - paid_amount), 0) as saldo')
            ->value('saldo');

        return response()->json([
            'success' => true,
            'data' => $paginated,
            'resumen' => [
                'no_autorizados' => $noAutorizados,
                'saldo_total' => $saldoTotal,
            ],
        ]);
    }

    /**
     * Detalle completo de una factura electrónica específica
     * (incluye XML firmado y autorizado, útil para soporte/debug).
     */
    public function show(ElectronicInvoice $electronicInvoice): JsonResponse
    {
        $this->authorizeElectronicInvoiceAccess($electronicInvoice);
        $electronicInvoice->load('sale.customer', 'sale.saleItems.product');

        return response()->json([
            'success' => true,
            'data' => $electronicInvoice,
        ]);
    }

    public function ride(ElectronicInvoice $electronicInvoice, SriRideService $rideService): Response
    {
        $this->authorizeElectronicInvoiceAccess($electronicInvoice);
        if (!$electronicInvoice->estaAutorizada()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta factura aún no está autorizada por el SRI.',
            ], 409);
        }

        $pdfContent = $electronicInvoice->tipo_comprobante === \App\Models\ElectronicInvoice::NOTA_CREDITO
            ? $rideService->generarPdfNotaCredito($electronicInvoice)
            : $rideService->generarPdf($electronicInvoice);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="factura_' . $electronicInvoice->numeroComprobante() . '.pdf"',
        ]);
    }

    /**
     * Descarga el XML -- el autorizado si ya existe (incluye el sello
     * del SRI), o el firmado si todavía no llegó a ese punto.
     */
    public function descargarXml(ElectronicInvoice $electronicInvoice): Response
    {
        $this->authorizeElectronicInvoiceAccess($electronicInvoice);
        $xml = $electronicInvoice->xml_autorizado ?: $electronicInvoice->xml_firmado;

        if (!$xml) {
            return response()->json([
                'success' => false,
                'message' => 'Todavía no hay un XML generado para este comprobante.',
            ], 409);
        }

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $electronicInvoice->clave_acceso . '.xml"',
        ]);
    }

    /**
     * La "ruta de emisión" completa del comprobante -- los 6 pasos con
     * su estado y hora, más lo necesario para los botones de acción
     * (ver PDF, descargar XML, reintentar).
     */
    public function ruta(ElectronicInvoice $electronicInvoice): JsonResponse
    {
        $this->authorizeElectronicInvoiceAccess($electronicInvoice);
        $electronicInvoice->load('sale.customer');

        return response()->json([
            'success' => true,
            'data' => [
                'numero_comprobante' => $electronicInvoice->numeroComprobante(),
                'tipo_comprobante' => $electronicInvoice->tipo_comprobante,
                'estado' => $electronicInvoice->estado,
                'clave_acceso' => $electronicInvoice->clave_acceso,
                'cliente' => $electronicInvoice->sale?->customer?->name,
                'creado_en' => optional($electronicInvoice->created_at)->toIso8601String(),
                'tiene_xml' => (bool) $electronicInvoice->xml_firmado,
                'puede_ver_pdf' => $electronicInvoice->estaAutorizada(),
                'puede_reintentar' => $electronicInvoice->puedeReintentarse(),
                'pasos' => $electronicInvoice->rutaEmision(),
            ],
        ]);
    }

    private function authorizeElectronicInvoiceAccess(ElectronicInvoice $electronicInvoice): void
    {
        $storeId = $this->requireCurrentStoreId();
        abort_unless((int) $electronicInvoice->store_id === $storeId, 403, 'No tiene permiso para acceder a este comprobante.');
    }
}
