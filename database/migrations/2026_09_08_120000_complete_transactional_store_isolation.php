<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completa el backfill que no podía resolverse solo desde warehouse_id.
 *
 * Las ocho transaccionales originales y credit_notes tienen una fuente de
 * tienda obligatoria, así que store_id pasa a NOT NULL. pos_register conserva
 * nullable para sesiones históricas sin actividad; las filas recuperables se
 * completan y todas las sesiones nuevas quedan protegidas por el modelo.
 */
return new class extends Migration
{
    private const REQUIRED_TABLES = [
        'sales',
        'purchases',
        'sales_return',
        'purchases_return',
        'quotations',
        'expenses',
        'adjustments',
        'holds',
        'credit_notes',
    ];

    public function up(): void
    {
        $this->backfillCreditNotes();
        $this->backfillRegisters();

        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'store_id')) {
                continue;
            }

            $orphans = DB::table($table)->whereNull('store_id')->count();
            if ($orphans > 0) {
                throw new \RuntimeException(
                    "No se puede hacer {$table}.store_id obligatorio: existen {$orphans} filas sin tienda."
                );
            }

            $this->changeStoreIdNullability($table, false);
        }
    }

    public function down(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'store_id')) {
                $this->changeStoreIdNullability($table, true);
            }
        }
    }

    private function backfillCreditNotes(): void
    {
        if (! Schema::hasTable('credit_notes') || ! Schema::hasColumn('credit_notes', 'store_id')) {
            return;
        }

        DB::table('credit_notes')->whereNull('store_id')->orderBy('id')->chunkById(500, function ($notes) {
            foreach ($notes as $note) {
                $storeId = DB::table('sales')->where('id', $note->sale_id)->value('store_id');
                if ($storeId !== null) {
                    DB::table('credit_notes')->where('id', $note->id)->update(['store_id' => $storeId]);
                }
            }
        });
    }

    private function backfillRegisters(): void
    {
        if (! Schema::hasTable('pos_register') || ! Schema::hasColumn('pos_register', 'store_id')) {
            return;
        }

        DB::table('pos_register')->whereNull('store_id')->orderBy('id')->chunkById(500, function ($registers) {
            foreach ($registers as $register) {
                $storeId = $register->warehouse_id
                    ? DB::table('warehouses')->where('id', $register->warehouse_id)->value('store_id')
                    : null;

                if ($storeId === null && $register->cash_register_id) {
                    $storeId = DB::table('cash_registers')->where('id', $register->cash_register_id)->value('store_id');
                }
                if ($storeId === null && Schema::hasTable('cash_movements')) {
                    $storeId = DB::table('cash_movements')
                        ->where('pos_register_id', $register->id)->whereNotNull('store_id')->value('store_id');
                }
                if ($storeId === null && Schema::hasTable('sales_payments')) {
                    $storeId = DB::table('sales_payments')
                        ->join('sales', 'sales.id', '=', 'sales_payments.sale_id')
                        ->where('sales_payments.pos_register_id', $register->id)
                        ->value('sales.store_id');
                }
                if ($storeId === null && Schema::hasTable('sales_return')) {
                    $storeId = DB::table('sales_return')
                        ->where('pos_register_id', $register->id)->value('store_id');
                }

                if ($storeId !== null) {
                    DB::table('pos_register')->where('id', $register->id)->update(['store_id' => $storeId]);
                }
            }
        });
    }

    private function changeStoreIdNullability(string $table, bool $nullable): void
    {
        // MySQL 8/MariaDB rechazan MODIFY/CHANGE mientras la columna está
        // usada por una FK (error 1832). Se reconstruye la constraint sin
        // tocar el índice compuesto agregado por la migración anterior.
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(['store_id']));
        Schema::table($table, function (Blueprint $blueprint) use ($nullable) {
            $column = $blueprint->unsignedBigInteger('store_id');
            if ($nullable) {
                $column->nullable();
            }
            $column->change();
        });
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint
            ->foreign('store_id')->references('id')->on('stores')
            ->cascadeOnUpdate()->restrictOnDelete());
    }
};
