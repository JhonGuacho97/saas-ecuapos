<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kardex: historial de movimientos de UN producto en UNA bodega, con
 * existencias corrientes y costo PROMEDIO PONDERADO. Se arma leyendo lo
 * que ya existe en Compras, Ventas y Transferencias -- no hay tabla
 * propia, así que no hace falta tocar esos módulos para nada.
 *
 * Costo promedio ponderado: cada ENTRADA (compra, transferencia entrante)
 * usa el costo real de esa transacción puntual. Cada SALIDA (venta,
 * transferencia saliente) se valora al costo promedio que había
 * ACUMULADO justo antes de esa salida -- se recalcula automáticamente
 * cada vez que entra mercadería nueva a un costo distinto. Es el método
 * clásico de Kardex cuando no se guarda el costo exacto de cada unidad
 * físicamente (FIFO real).
 */
class KardexAPIController extends AppBaseController
{
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->get('warehouse_id');
        $productId = $request->get('product_id');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $movementType = $request->get('movement_type', 'all');

        if (! $warehouseId || ! $productId || ! $startDate || ! $endDate) {
            return $this->sendError('Selecciona bodega, producto y rango de fechas.');
        }

        $product = Product::find($productId);
        if (! $product) {
            return $this->sendError('Producto no encontrado.');
        }
        $this->authorizeStoreOwnership($product);
        $this->authorizeWarehouseAccess((int) $warehouseId);

        // Todos los movimientos ANTES de la fecha de inicio, para calcular
        // cuánta cantidad y a qué costo promedio se llegó al arrancar el
        // rango pedido.
        $priorMovements = $this->fetchMovements($productId, $warehouseId, null, $startDate);
        [$openingQty, $openingAvgCost] = $this->replay($priorMovements, 0, 0);

        // Movimientos DENTRO del rango pedido.
        $movements = $this->fetchMovements($productId, $warehouseId, $startDate, $endDate);

        // Se calculan las existencias con TODOS los movimientos del rango,
        // sin importar el filtro de tipo -- si se calculara ya filtrado,
        // una salida filtrada sola nunca vería sumar las entradas de en
        // medio y el saldo se iría en negativo sin sentido.
        $qty = $openingQty;
        $avgCost = $openingAvgCost;
        $rows = [];
        foreach ($movements as $m) {
            if ($m['type'] === 'entrada') {
                $totalCostBefore = $qty * $avgCost;
                $totalCostNew = $m['quantity'] * $m['unit_cost'];
                $qty += $m['quantity'];
                $avgCost = $qty > 0 ? ($totalCostBefore + $totalCostNew) / $qty : 0;

                $rows[] = [
                    'date' => $m['date'],
                    'detail' => $m['detail'],
                    'type' => 'entrada',
                    'entrada_quantity' => $m['quantity'],
                    'entrada_cost' => $m['unit_cost'],
                    'entrada_total' => round($m['quantity'] * $m['unit_cost'], 4),
                    'salida_quantity' => null,
                    'salida_cost' => null,
                    'salida_total' => null,
                    'balance_quantity' => $qty,
                    'balance_cost' => round($avgCost, 4),
                    'balance_total' => round($qty * $avgCost, 4),
                ];
            } else {
                // La salida se valora al costo promedio QUE HABÍA justo
                // antes de esta salida -- no cambia el promedio en sí,
                // solo reduce la cantidad.
                $costForThisExit = $avgCost;
                $qty -= $m['quantity'];

                $rows[] = [
                    'date' => $m['date'],
                    'detail' => $m['detail'],
                    'type' => 'salida',
                    'entrada_quantity' => null,
                    'entrada_cost' => null,
                    'entrada_total' => null,
                    'salida_quantity' => $m['quantity'],
                    'salida_cost' => round($costForThisExit, 4),
                    'salida_total' => round($m['quantity'] * $costForThisExit, 4),
                    'balance_quantity' => $qty,
                    'balance_cost' => round($avgCost, 4),
                    'balance_total' => round($qty * $avgCost, 4),
                ];
            }
        }

        // El filtro de tipo de movimiento se aplica AL FINAL, solo para
        // decidir qué filas mostrar -- las existencias de las filas que sí
        // se muestran ya reflejan el cálculo completo de arriba.
        if (in_array($movementType, ['entrada', 'salida'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['type'] === $movementType));
        }

        return $this->sendResponse([
            'opening_balance' => $openingQty,
            'opening_cost' => round($openingAvgCost, 4),
            'rows' => $rows,
            'total' => count($rows),
        ], 'Kardex retrieved successfully.');
    }

    /**
     * Junta compras (entrada), ventas (salida), transferencias entrantes
     * (entrada) y salientes (salida) para un producto+bodega, entre dos
     * fechas -- o desde el principio de los tiempos si $startDate es null
     * (para calcular el saldo de arranque).
     */
    private function fetchMovements($productId, $warehouseId, $startDate, $endDate)
    {
        $movements = collect();

        $purchaseQuery = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.warehouse_id', $warehouseId)
            ->where('purchase_items.product_id', $productId)
            ->where('purchases.status', Purchase::RECEIVED);
        if ($startDate) {
            $purchaseQuery->whereBetween('purchases.date', [$startDate, $endDate]);
        } else {
            $purchaseQuery->where('purchases.date', '<', $endDate);
        }
        $movements = $movements->concat(
            $purchaseQuery->select(
                'purchases.date',
                'purchases.reference_code',
                'purchase_items.quantity',
                'purchase_items.net_unit_cost as unit_cost'
            )->get()->map(fn ($row) => [
                'date' => $row->date,
                'detail' => 'Compra '.$row->reference_code,
                'type' => 'entrada',
                'quantity' => (float) $row->quantity,
                'unit_cost' => (float) $row->unit_cost,
            ])
        );

        $saleQuery = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.warehouse_id', $warehouseId)
            ->where('sale_items.product_id', $productId)
            ->where('sales.status', Sale::COMPLETED);
        if ($startDate) {
            $saleQuery->whereBetween('sales.date', [$startDate, $endDate]);
        } else {
            $saleQuery->where('sales.date', '<', $endDate);
        }
        $movements = $movements->concat(
            $saleQuery->select('sales.date', 'sales.reference_code', 'sale_items.quantity')
                ->get()->map(fn ($row) => [
                    'date' => $row->date,
                    'detail' => 'Venta '.$row->reference_code,
                    'type' => 'salida',
                    'quantity' => (float) $row->quantity,
                    'unit_cost' => null, // se valora al promedio corriente, no acá
                ])
        );

        $transferOutQuery = DB::table('transfer_items')
            ->join('transfers', 'transfers.id', '=', 'transfer_items.transfer_id')
            ->where('transfers.from_warehouse_id', $warehouseId)
            ->where('transfer_items.product_id', $productId)
            ->where('transfers.status', Transfer::COMPLETED);
        if ($startDate) {
            $transferOutQuery->whereBetween('transfers.date', [$startDate, $endDate]);
        } else {
            $transferOutQuery->where('transfers.date', '<', $endDate);
        }
        $movements = $movements->concat(
            $transferOutQuery->select('transfers.date', 'transfers.reference_code', 'transfer_items.quantity')
                ->get()->map(fn ($row) => [
                    'date' => $row->date,
                    'detail' => 'Transferencia (salida) '.$row->reference_code,
                    'type' => 'salida',
                    'quantity' => (float) $row->quantity,
                    'unit_cost' => null,
                ])
        );

        $transferInQuery = DB::table('transfer_items')
            ->join('transfers', 'transfers.id', '=', 'transfer_items.transfer_id')
            ->where('transfers.to_warehouse_id', $warehouseId)
            ->where('transfer_items.product_id', $productId)
            ->where('transfers.status', Transfer::COMPLETED);
        if ($startDate) {
            $transferInQuery->whereBetween('transfers.date', [$startDate, $endDate]);
        } else {
            $transferInQuery->where('transfers.date', '<', $endDate);
        }
        $movements = $movements->concat(
            $transferInQuery->select(
                'transfers.date',
                'transfers.reference_code',
                'transfer_items.quantity',
                'transfer_items.net_unit_price as unit_cost'
            )->get()->map(fn ($row) => [
                'date' => $row->date,
                'detail' => 'Transferencia (entrada) '.$row->reference_code,
                'type' => 'entrada',
                'quantity' => (float) $row->quantity,
                'unit_cost' => (float) $row->unit_cost,
            ])
        );

        return $movements->sortBy('date')->values();
    }

    /**
     * "Reproduce" una lista de movimientos ya ordenada, arrancando de una
     * cantidad/costo dados, y devuelve [cantidad_final, costo_promedio_final].
     * Se usa para calcular el saldo de arranque del rango pedido, jugando
     * de nuevo TODO lo que pasó antes.
     */
    private function replay($movements, float $qty, float $avgCost): array
    {
        foreach ($movements as $m) {
            if ($m['type'] === 'entrada') {
                $totalCostBefore = $qty * $avgCost;
                $totalCostNew = $m['quantity'] * $m['unit_cost'];
                $qty += $m['quantity'];
                $avgCost = $qty > 0 ? ($totalCostBefore + $totalCostNew) / $qty : 0;
            } else {
                $qty -= $m['quantity'];
            }
        }

        return [$qty, $avgCost];
    }
}
