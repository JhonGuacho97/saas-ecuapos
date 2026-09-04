<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Jobs\EmitirFacturaJob;
use App\Models\CreditNote;
use App\Models\ElectronicInvoice;
use App\Repositories\CreditNoteRepository;
use App\Services\SaaS\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditNoteAPIController extends AppBaseController
{
    private CreditNoteRepository $creditNoteRepository;

    public function __construct(
        CreditNoteRepository $creditNoteRepository,
        private readonly EntitlementService $entitlements
    )
    {
        $this->creditNoteRepository = $creditNoteRepository;
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = getPageSize($request);
        $search = $request->get('search');

        $query = CreditNote::with(['customer', 'sale', 'category'])->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_code', 'like', "%{$search}%")
                    ->orWhere('numero_comprobante_modificado', 'like', "%{$search}%")
                    ->orWhere('motivo', 'like', "%{$search}%");
            });
        }

        if ($restricted = $this->restrictedWarehouseId()) {
            $query->where('warehouse_id', $restricted);
        }
        $this->scopeQueryToCurrentStore($query);

        $creditNotes = $query->paginate($perPage, ['*'], 'page', (int) $request->input('page', 1));

        $creditNotes->getCollection()->transform(fn (CreditNote $cn) => $cn->prepareAttributes() + ['id' => $cn->id]);

        return response()->json(['success' => true, 'data' => $creditNotes]);
    }

    public function show(CreditNote $creditNote): JsonResponse
    {
        $this->authorizeWarehouseAccess($creditNote->warehouse_id);
        $creditNote->load(['customer', 'sale', 'warehouse', 'category', 'creditNoteItems.product', 'electronicInvoice']);

        $ei = $creditNote->electronicInvoice;

        return response()->json([
            'success' => true,
            'data' => $creditNote->prepareAttributes() + [
                'id' => $creditNote->id,
                'electronic_invoice_estado' => $ei?->estado,
                'electronic_invoice_mensajes' => $ei?->mensajes_sri,
                'electronic_invoice_numero_autorizacion' => $ei?->numero_autorizacion,
                'electronic_invoice_id' => $ei?->id,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required',
            'sale_id' => 'required|exists:sales,id',
            'customer_id' => 'required|exists:customers,id',
            'concepto' => 'required|string',
            'generar_como' => 'required|string',
            'motivo' => 'required|string',
            'credit_note_items' => 'required|array|min:1',
            'credit_note_items.*.product_id' => 'required|exists:products,id',
            'credit_note_items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $this->authorizeWarehouseAccess($request->input('warehouse_id'));
        $creditNote = $this->creditNoteRepository->storeCreditNote($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Nota de crédito creada correctamente.',
            'data' => $creditNote->prepareAttributes() + ['id' => $creditNote->id],
        ], 201);
    }

    /**
     * Dispara la emisión electrónica de la nota de crédito ante el SRI
     * -- reutiliza EmitirFacturaJob (mismo firmado, envío, logs y
     * clasificación de errores que ya usa Factura/Nota de Débito).
     */
    public function emitir(CreditNote $creditNote): JsonResponse
    {
        $this->authorizeWarehouseAccess($creditNote->warehouse_id);
        $existente = ElectronicInvoice::where('credit_note_id', $creditNote->id)
            ->whereNotIn('estado', [
                ElectronicInvoice::DEVUELTA,
                ElectronicInvoice::NO_AUTORIZADA,
                ElectronicInvoice::ERROR_TEMPORAL_SRI,
            ])
            ->first();

        if ($existente) {
            return response()->json([
                'success' => false,
                'message' => 'Esta nota de crédito ya tiene un comprobante electrónico activo.',
            ], 422);
        }

        $creditNote->loadMissing('warehouse.store');
        $hasPreviousAttempt = ElectronicInvoice::where('credit_note_id', $creditNote->id)->exists();
        $reserved = $this->entitlements->reserveElectronicDocument(
            (int) $creditNote->warehouse?->store?->organization_id,
            EntitlementService::SOURCE_CREDIT_NOTE,
            $creditNote->id
        );

        // Entre el clic y la ejecución del job todavía no existe una fila
        // en electronic_invoices. La reserva única cubre esa ventana y evita
        // encolar dos veces el mismo documento por un doble clic.
        if (! $reserved && ! $hasPreviousAttempt) {
            return response()->json([
                'success' => true,
                'message' => 'La nota de crédito electrónica ya está en la cola de procesamiento.',
            ], 202);
        }

        try {
            EmitirFacturaJob::dispatch(
                $creditNote->sale_id,
                ElectronicInvoice::NOTA_CREDITO,
                [],
                null,
                $creditNote->id
            )->afterCommit();
        } catch (\Throwable $exception) {
            if ($reserved) {
                $this->entitlements->releaseElectronicDocument(
                    EntitlementService::SOURCE_CREDIT_NOTE,
                    $creditNote->id
                );
            }
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => 'Emisión en proceso. El comprobante se generará en unos segundos.',
        ]);
    }

    /**
     * Cancela/anula una nota de crédito huérfana (sin comprobante
     * electrónico válido ante el SRI) -- ver CreditNoteRepository::
     * cancelarCreditNote() para las reglas exactas de cuándo se permite.
     */
    public function cancelar(CreditNote $creditNote): JsonResponse
    {
        $this->authorizeWarehouseAccess($creditNote->warehouse_id);
        $creditNote = $this->creditNoteRepository->cancelarCreditNote($creditNote->id);

        return response()->json([
            'success' => true,
            'message' => 'Nota de crédito cancelada correctamente.',
            'data' => $creditNote->prepareAttributes() + ['id' => $creditNote->id],
        ]);
    }

    /**
     * Facturas de un cliente puntual, para el modal "Listado de
     * Comprobante" que se abre al presionar el botón de buscar.
     */
    public function facturasDeCliente(Request $request, int $customer): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);
        $page = (int) $request->input('page', 1);
        $search = (string) $request->get('search', '');

        $resultado = $this->creditNoteRepository->facturasDeCliente($customer, $perPage, $page, $search, $this->restrictedWarehouseId());

        return response()->json(['success' => true, 'data' => $resultado]);
    }

    /**
     * Busca una factura por número de comprobante o código interno,
     * para autocompletar el cliente y el saldo al armar la nota nueva.
     */
    public function buscarFactura(Request $request): JsonResponse
    {
        $request->validate(['busqueda' => 'required|string']);

        $resultado = $this->creditNoteRepository->buscarFactura($request->get('busqueda'), $this->restrictedWarehouseId());

        if (!$resultado) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró ninguna factura con ese número.',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $resultado]);
    }
}
