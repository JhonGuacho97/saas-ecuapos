<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreatePOSRegisterRequest;
use App\Http\Resources\POSRegisterCollection;
use App\Http\Resources\POSRegisterResource;
use App\Models\POSRegister;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Warehouse;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SalesPayment;
use App\Models\User;
use App\Repositories\POSRegisterRepository;
use App\Services\CashControlService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class POSRegisterAPIController extends AppBaseController
{
    public $posReg;

    public function __construct(POSRegisterRepository $posReg, private readonly CashControlService $cashControl)
    {
        $this->posReg = $posReg;
    }

    public function entry(CreatePOSRegisterRequest $request)
    {
        $input = $request->all();
        $request->validate(['cash_register_id' => ['nullable', 'integer', 'exists:cash_registers,id']]);
        $input['user_id'] = Auth::id();

        // El modal de apertura de caja (PosRegisterModel.js, montado desde
        // Header.js) nunca mandó warehouse_id -- a diferencia del payload
        // de venta, que sí lo saca del selector de almacén de la pantalla
        // POS. Sin esto, pos_register.warehouse_id quedaba NULL en TODA
        // caja nueva, y como registerReport() ahora se scopea por tienda
        // vía warehouse_id, esas cajas NULL desaparecían de cualquier
        // informe -- las viejas ya no se mezclaban entre tiendas, pero las
        // nuevas dejaban de aparecer del todo. Mismo fallback que ya usa
        // el frontend para el selector cuando no hay uno explícito:
        // almacén propio del usuario, si no el de la tienda activa.
        if (empty($input['warehouse_id'])) {
            $input['warehouse_id'] = $this->defaultWarehouseForCurrentStore()?->id;
        }
        if (empty($input['warehouse_id'])) {
            throw ValidationException::withMessages(['warehouse_id' => 'No tienes un almacén activo disponible en esta tienda.']);
        }

        $storeId = $this->requireCurrentStoreId();

        DB::transaction(function () use (&$input, $storeId) {
            User::whereKey(Auth::id())->lockForUpdate()->firstOrFail();
            if (POSRegister::openForUser((int) Auth::id())->forStore($storeId)->exists()) {
                throw ValidationException::withMessages(['register' => 'Ya tienes una caja abierta en esta tienda.']);
            }

            $warehouse = Warehouse::findOrFail($input['warehouse_id']);
            $this->authorizeWarehouseAccess($warehouse->id);
            $storeId = (int) $warehouse->store_id;
            $cashRegister = ! empty($input['cash_register_id'])
                ? CashRegister::whereKey($input['cash_register_id'])->lockForUpdate()->firstOrFail()
                : CashRegister::firstOrCreate(
                    ['store_id' => $storeId, 'warehouse_id' => $warehouse->id, 'code' => 'MAIN-'.$warehouse->id],
                    ['name' => 'Caja principal', 'is_active' => true]
                );
            if ((int) $cashRegister->store_id !== $storeId || (int) $cashRegister->warehouse_id !== (int) $warehouse->id || ! $cashRegister->is_active) {
                throw ValidationException::withMessages(['cash_register_id' => 'La caja seleccionada no está disponible para esta sucursal.']);
            }
            $cashRegister = CashRegister::whereKey($cashRegister->id)->lockForUpdate()->firstOrFail();
            if (POSRegister::where('cash_register_id', $cashRegister->id)->whereNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['cash_register_id' => 'Esta caja ya está siendo utilizada por otro cajero.']);
            }
            $input['cash_register_id'] = $cashRegister->id;

            $register = POSRegister::create($input);
            CashMovement::create([
                'pos_register_id' => $register->id,
                'cash_register_id' => $cashRegister->id,
                'store_id' => $storeId,
                'warehouse_id' => $warehouse->id,
                'user_id' => Auth::id(),
                'type' => CashMovement::OPENING,
                'direction' => CashMovement::IN,
                'amount' => $register->cash_in_hand,
                'balance_after' => $register->cash_in_hand,
                'description' => 'Apertura de caja',
            ]);
        });

        return $this->sendSuccess('Register entry added successfully.');
    }

    public function closeRegister(Request $request)
    {
        $input = $request->validate([
            'cash_in_hand_while_closing' => ['required', 'numeric', 'min:0'],
            'closing_denominations' => ['nullable', 'array'],
            'closing_denominations.*.value' => ['required', 'numeric', 'min:0.01'],
            'closing_denominations.*.quantity' => ['required', 'integer', 'min:0'],
            'closing_denominations.*.subtotal' => ['nullable', 'numeric', 'min:0'],
            'discrepancy_reason' => ['nullable', 'string', 'max:100'],
            'discrepancy_note' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $storeId = $this->requireCurrentStoreId();

        DB::transaction(function () use ($input, $storeId) {
            $register = POSRegister::openForUser((int) Auth::id())
                ->forStore($storeId)->lockForUpdate()->latest()->first();
            if (! $register) {
                throw ValidationException::withMessages(['register' => 'No existe una sesión de caja abierta.']);
            }

            $countedCash = round((float) $input['cash_in_hand_while_closing'], 4);
            $denominationTotal = round(collect($input['closing_denominations'] ?? [])->sum(
                fn ($row) => (float) $row['value'] * (int) $row['quantity']
            ), 4);
            if (! empty($input['closing_denominations']) && abs($denominationTotal - $countedCash) > 0.009) {
                throw ValidationException::withMessages([
                    'closing_denominations' => 'El total de denominaciones no coincide con el efectivo contado.',
                ]);
            }

            $data = $this->getRegisterData($register->created_at->toDateTimeString(), now()->toDateTimeString(), $register);
            $expectedCash = $this->cashControl->currentBalance($register);
            $difference = round($countedCash - $expectedCash, 4);
            if (abs($difference) > 0.009 && empty($input['discrepancy_reason'])) {
                throw ValidationException::withMessages(['discrepancy_reason' => 'Debes indicar el motivo del faltante o sobrante.']);
            }
            if (($input['discrepancy_reason'] ?? null) === 'Otro' && empty($input['discrepancy_note'])) {
                throw ValidationException::withMessages(['discrepancy_note' => 'Describe el motivo de la diferencia.']);
            }

            $register->closed_at = now();
            $register->closed_by = Auth::id();
            $register->cash_in_hand_while_closing = $countedCash;
            $register->closing_denominations = $input['closing_denominations'] ?? null;
            $register->discrepancy_reason = abs($difference) > 0.009 ? $input['discrepancy_reason'] : null;
            $register->discrepancy_note = abs($difference) > 0.009 ? ($input['discrepancy_note'] ?? null) : null;
            $register->expected_cash = $expectedCash;
            $register->cash_difference = $difference;
            $register->reconciliation_status = abs($difference) <= 0.009 ? 'BALANCED' : 'PENDING';
            $register->bank_transfer = $data['today_sales_bank_transfer_payment'];
            $register->cheque = $data['today_sales_cheque_payment'];
            $register->other = $data['today_sales_other_payment'];
            $register->total_sale = $data['today_sales_amount'];
            $register->total_return = $data['today_sales_return_amount'];
            $register->total_amount = $data['today_sales_payment_amount'];
            $register->notes = $input['notes'] ?? null;
            $register->save();
        });

        return $this->sendSuccess('Register entry updated successfully.');
    }

    public function availableCashRegisters(Request $request)
    {
        $warehouseId = (int) ($request->input('warehouse_id') ?: $this->defaultWarehouseForCurrentStore()?->id);
        if (! $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => 'No tienes un almacén activo disponible en esta tienda.']);
        }
        $this->authorizeWarehouseAccess($warehouseId);
        $storeId = (int) $this->currentStoreId();

        $registers = CashRegister::with(['activeSession.user:id,first_name,last_name'])
            ->where('store_id', $storeId)->where('warehouse_id', $warehouseId)
            ->where('is_active', true)->orderBy('name')->get()->map(fn (CashRegister $register) => [
                'id' => $register->id,
                'name' => $register->name,
                'code' => $register->code,
                'available' => ! $register->activeSession,
                'current_user' => $register->activeSession?->user,
            ]);

        return $this->sendResponse($registers, 'Cajas disponibles obtenidas correctamente.');
    }

    public function getRegisterDetails(Request $request)
    {
        $register = POSRegister::openForUser((int) Auth::id())
            ->forStore($this->requireCurrentStoreId())
            ->latest()->first();

        if (! $register) {
            throw ValidationException::withMessages(['register' => 'No existe una sesión de caja abierta en esta tienda.']);
        }

        // Límite del día calendario de Ecuador, convertido a UTC para
        // compararlo contra created_at (que se guarda en UTC real).
        $startDate = now('America/Guayaquil')->startOfDay()->utc()->toDateTimeString();
        $endDate = now('America/Guayaquil')->endOfDay()->utc()->toDateTimeString();
        if (! empty($register)) {
            $startDate = $register->created_at->toDateTimeString();
        }

        $data = $this->getRegisterData($startDate, $endDate, $register);
        $data['cash_in_hand'] = $register->cash_in_hand;
        $data['opening_denominations'] = $register->opening_denominations ?? [];
        $data['total_cash_amount'] = $this->cashControl->currentBalance($register);
        $data['manual_cash_net'] = $this->cashControl->manualNet($register);

        return $this->sendResponse($data, 'Details retrieved successfully');
    }

    public function registerReport(Request $request)
    {
        $perPage = getPageSize($request);
        $query = $this->registerReportQuery($request);
        $summary = $this->registerReportSummary(clone $query);
        $register = $query->with([
            'user:id,first_name,last_name,email',
            'warehouse:id,name,store_id',
            'cashRegister:id,name,code',
            'closedBy:id,first_name,last_name',
            'reviewedBy:id,first_name,last_name',
        ])->orderByDesc('closed_at')->paginate($perPage);

        POSRegisterResource::usingWithCollection();

        return (new POSRegisterCollection($register))->additional([
            'summary' => $summary,
            'filter_options' => $this->registerReportFilterOptions(),
        ]);
    }

    /**
     * Movimientos históricos de un turno cerrado. Es deliberadamente de
     * solo lectura: las revisiones y reversos permanecen en Control de cajas.
     */
    public function registerReportMovements(Request $request, POSRegister $session)
    {
        $session->loadMissing(['warehouse:id,name,store_id,is_active']);
        $storeId = (int) $this->currentStoreId();
        if (! $session->closed_at
            || (int) $session->warehouse?->store_id !== $storeId
            || ! $session->warehouse?->is_active
            || (! Auth::user()->isUnrestrictedAdmin() && (int) $session->user_id !== (int) Auth::id())) {
            abort(404);
        }

        $validated = $request->validate([
            'type' => ['nullable', Rule::in([
                CashMovement::OPENING, CashMovement::MANUAL_INCOME, CashMovement::MANUAL_EXPENSE,
                CashMovement::WITHDRAWAL, CashMovement::SALE_PAYMENT, CashMovement::EXPENSE_PAYMENT,
                CashMovement::CASH_REFUND, CashMovement::REVERSAL, CashMovement::TRANSFER_IN,
                CashMovement::TRANSFER_OUT,
            ])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = $session->movements();
        $summary = [
            'cash_sales' => (float) (clone $base)->where('type', CashMovement::SALE_PAYMENT)->where('direction', CashMovement::IN)->sum('amount'),
            'manual_income' => (float) (clone $base)->where('type', CashMovement::MANUAL_INCOME)->where('direction', CashMovement::IN)->sum('amount'),
            'total_out' => (float) (clone $base)->where('direction', CashMovement::OUT)->sum('amount'),
            'refunds' => (float) (clone $base)->where('type', CashMovement::CASH_REFUND)->sum('amount'),
            'transfers_in' => (float) (clone $base)->where('type', CashMovement::TRANSFER_IN)->sum('amount'),
            'transfers_out' => (float) (clone $base)->where('type', CashMovement::TRANSFER_OUT)->sum('amount'),
        ];
        $search = trim((string) ($validated['search'] ?? ''));
        $movements = $session->movements()
            ->when($validated['type'] ?? null, fn ($builder, $type) => $builder->where('type', $type))
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(fn ($nested) => $nested->where('description', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%"));
            })
            ->with(['user:id,first_name,last_name', 'approvedBy:id,first_name,last_name'])
            ->latest()->paginate((int) ($validated['per_page'] ?? 8));

        $payload = $movements->toArray();
        $payload['summary'] = $summary;

        return response()->json($payload);
    }

    private function registerReportQuery(Request $request): Builder
    {
        $input = $request->all();
        $query = POSRegister::query()->whereNotNull('closed_at');
        $this->scopeQueryToCurrentStore($query);

        if (Auth::user()->isUnrestrictedAdmin()) {
            if (! empty($input['user_id'])) {
                if ($storeId = $this->currentStoreId()) {
                    $belongs = User::whereKey($input['user_id'])
                        ->whereHas('stores', fn ($store) => $store->where('stores.id', $storeId))->exists();
                    if (! $belongs) {
                        throw new AccessDeniedHttpException('Ese usuario no pertenece a la tienda activa.');
                    }
                }
                $query->where('user_id', (int) $input['user_id']);
            }
        } else {
            $query->where('user_id', Auth::id());
        }

        $query->when($request->filled('warehouse_id'), fn ($builder) => $builder->where('warehouse_id', (int) $request->input('warehouse_id')))
            ->when($request->filled('cash_register_id'), fn ($builder) => $builder->where('cash_register_id', (int) $request->input('cash_register_id')))
            ->when($request->filled('reconciliation_status'), fn ($builder) => $builder->where('reconciliation_status', $request->input('reconciliation_status')));

        if ($request->input('difference') === 'balanced') {
            $query->whereBetween('cash_difference', [-0.009, 0.009]);
        } elseif ($request->input('difference') === 'shortage') {
            $query->where('cash_difference', '<', -0.009);
        } elseif ($request->input('difference') === 'surplus') {
            $query->where('cash_difference', '>', 0.009);
        }

        if (! empty($input['start_date'])) {
            $query->where('created_at', '>=', Carbon::parse($input['start_date'], 'America/Guayaquil')->startOfDay()->utc());
        }
        if (! empty($input['end_date'])) {
            $query->where('closed_at', '<=', Carbon::parse($input['end_date'], 'America/Guayaquil')->endOfDay()->utc());
        }

        $search = trim((string) ($input['search'] ?? data_get($input, 'filter.search', '')));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('notes', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('cashRegister', fn ($cashRegister) => $cashRegister->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"));
            });
        }

        return $query;
    }

    private function registerReportSummary(Builder $query): array
    {
        $totals = $query->selectRaw('COUNT(*) AS sessions')
            ->selectRaw('COALESCE(SUM(expected_cash), 0) AS expected_cash')
            ->selectRaw('COALESCE(SUM(cash_in_hand_while_closing), 0) AS counted_cash')
            ->selectRaw('COALESCE(SUM(cash_difference), 0) AS net_difference')
            ->selectRaw('COALESCE(SUM(ABS(cash_difference)), 0) AS absolute_difference')
            ->selectRaw('SUM(CASE WHEN ABS(COALESCE(cash_difference, 0)) <= 0.009 THEN 1 ELSE 0 END) AS balanced')
            ->selectRaw("SUM(CASE WHEN reconciliation_status = 'PENDING' THEN 1 ELSE 0 END) AS pending")
            ->first();

        return [
            'sessions' => (int) $totals->sessions,
            'expected_cash' => (float) $totals->expected_cash,
            'counted_cash' => (float) $totals->counted_cash,
            'net_difference' => (float) $totals->net_difference,
            'absolute_difference' => (float) $totals->absolute_difference,
            'balanced' => (int) $totals->balanced,
            'pending' => (int) $totals->pending,
            'balanced_rate' => (int) $totals->sessions > 0
                ? round(((int) $totals->balanced / (int) $totals->sessions) * 100, 1)
                : 0,
        ];
    }

    private function registerReportFilterOptions(): array
    {
        $storeId = (int) $this->currentStoreId();
        $warehouses = Warehouse::where('store_id', $storeId)->active()->orderBy('name')->get(['id', 'name']);
        $warehouseIds = $warehouses->pluck('id');

        return [
            'warehouses' => $warehouses,
            'cash_registers' => CashRegister::where('store_id', $storeId)->whereIn('warehouse_id', $warehouseIds)
                ->orderBy('name')->get(['id', 'name', 'code', 'warehouse_id']),
        ];
    }

    public function getRegisterData($startDate, $endDate, ?POSRegister $register = null)
    {
        $sales = Sale::where('user_id', Auth::id())
            ->whereBetween('created_at', [$startDate, $endDate]);
        if ($register) {
            $sales->where('warehouse_id', $register->warehouse_id);
        } else {
            $this->scopeQueryToCurrentStore($sales);
        }

        $totalGrandTotalAmount = (clone $sales)->sum('grand_total');
        $saleIds = $sales->pluck('id')
            ->toArray();

        $payments = SalesPayment::query();
        if ($register) {
            $payments->where('pos_register_id', $register->id);
        } else {
            $payments->whereIn('sale_id', $saleIds)->whereBetween('created_at', [$startDate, $endDate]);
        }

        $data['today_sales_cash_payment'] = (clone $payments)
            ->where('payment_type', SalesPayment::CASH)
            ->sum('amount');

        $data['todays_specific_sales_cash_payment'] = (clone $payments)
            ->where('payment_type', SalesPayment::CASH)
            ->sum('amount');

        $data['today_sales_cheque_payment'] = (clone $payments)
            ->where('payment_type', SalesPayment::CHEQUE)
            ->sum('amount');

        $data['today_sales_bank_transfer_payment'] = (clone $payments)
            ->where('payment_type', SalesPayment::BANK_TRANSFER)
            ->sum('amount');

        $data['today_sales_other_payment'] = (clone $payments)
            ->where('payment_type', SalesPayment::OTHER)
            ->sum('amount');

        $data['today_sales_amount'] = $totalGrandTotalAmount;

        $returns = SaleReturn::query();
        if ($register) {
            $returns->where('pos_register_id', $register->id);
        } else {
            $returns->whereIn('sale_id', $saleIds)->whereBetween('created_at', [$startDate, $endDate]);
        }
        $data['today_sales_return_amount'] = (clone $returns)->sum('grand_total');

        // Este es el total efectivamente recibido durante el turno. Antes se
        // sobrescribía con ventas menos devoluciones, inflando cierres cuando
        // existían ventas parciales o pendientes.
        $data['today_sales_payment_amount'] = (clone $payments)->sum('amount');
        // Antes filtraba por sale.payment_type == CASH -- ese campo solo
        // guarda el PRIMER método de pago recibido (ver SaleRepository),
        // así que una venta pagada mitad tarjeta / mitad efectivo (tarjeta
        // registrada primero) quedaba fuera de este cálculo aunque sí
        // hubiera efectivo real de por medio que devolver. Se cambia a
        // comprobar si la venta tuvo ALGÚN pago en efectivo real
        // (SalesPayment), no solo el primero.
        // Nota: esto sigue asumiendo la devolución completa como "en
        // efectivo" cuando hubo cualquier pago en efectivo -- repartir el
        // monto exacto proporcionalmente entre los métodos de pago
        // originales es una decisión de negocio que no está definida hoy
        // (¿se devuelve en el mismo método que se cobró? ¿a criterio del
        // cajero?) y queda fuera de este fix.
        $data['refunded_cash'] = (clone $returns)
            ->where(function (Builder $query) {
                $query->where('payment_type', SaleReturn::CASH)
                    ->orWhere(function (Builder $legacy) {
                        $legacy->where(function (Builder $payment) {
                            $payment->whereNull('payment_type')->orWhere('payment_type', 0);
                        })->whereHas('sale.payments', function (Builder $payment) {
                            $payment->where('payment_type', SalesPayment::CASH);
                        });
                    });
            })
            ->sum('grand_total');

        return $data;
    }
}
