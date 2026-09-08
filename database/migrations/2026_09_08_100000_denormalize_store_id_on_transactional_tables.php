<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las tablas transaccionales solo tenían warehouse_id: para saber a qué
 * inquilino pertenece una venta había que saltar a warehouses. Eso obliga
 * a que cada listado resuelva primero los IDs de bodega de la tienda
 * (scopeQueryToCurrentStore() los materializa en PHP y arma un whereIn)
 * y deja los reportes apoyados en un único índice de la FK.
 *
 * Al bajar store_id a la propia fila, el filtro por inquilino pasa a ser
 * una comparación directa -- indexable junto a la fecha, que es cómo se
 * consulta de verdad -- y habilita el scope global de BelongsToStore
 * sobre ventas, compras y devoluciones.
 */
return new class extends Migration
{
    /** Tablas con warehouse_id NOT NULL: el backfill siempre resuelve. */
    private const TABLES = [
        'sales' => 'date',
        'purchases' => 'date',
        'sales_return' => 'date',
        'purchases_return' => 'date',
        'quotations' => 'date',
        'expenses' => 'date',
        'adjustments' => 'date',
        'holds' => 'date',
        // warehouse_id nullable: se agrega la columna y se rellena lo que
        // se pueda, pero NO reciben scope global todavía -- una fila sin
        // bodega quedaría sin store_id y el scope la escondería en vez de
        // fallar ruidosamente. Ver docs/saas/aislamiento-datos.md.
        'credit_notes' => 'date',
        'pos_register' => 'created_at',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'store_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('store_id')->nullable()->after('warehouse_id')
                    ->constrained('stores')->cascadeOnUpdate()->restrictOnDelete();
            });

            DB::table($tableName)
                ->join('warehouses', 'warehouses.id', '=', $tableName.'.warehouse_id')
                ->whereNull($tableName.'.store_id')
                ->update([$tableName.'.store_id' => DB::raw('warehouses.store_id')]);

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->index(['store_id', self::TABLES[$tableName]]);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'store_id')) {
                continue;
            }

            // La FK primero: MySQL se apoya en el índice compuesto para
            // sostenerla y rechaza el DROP INDEX mientras exista
            // ("Cannot drop index: needed in a foreign key constraint").
            Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign(['store_id']));
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex(['store_id', self::TABLES[$tableName]]);
                $table->dropColumn('store_id');
            });
        }
    }
};
