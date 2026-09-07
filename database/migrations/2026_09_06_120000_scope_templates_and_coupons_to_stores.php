<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['mail_templates', 'sms_templates', 'coupon_codes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('store_id')->nullable()->after('id')
                    ->constrained('stores')->cascadeOnUpdate()->nullOnDelete();
                $table->index('store_id');
            });
        }

        Schema::table('mail_templates', fn (Blueprint $table) => $table->unique(['store_id', 'type']));
        Schema::table('sms_templates', fn (Blueprint $table) => $table->unique(['store_id', 'type']));
        Schema::table('coupon_codes', function (Blueprint $table) {
            $table->dropUnique('coupon_codes_code_unique');
            $table->unique(['store_id', 'code']);
        });

        // Los cupones heredados ya vinculados a productos de una sola
        // tienda pueden asignarse sin ambigüedad. Los que mezclan tiendas
        // quedan sin asignar y, por seguridad, no se exponen a ningún tenant.
        DB::table('coupon_codes')->orderBy('id')->pluck('id')->each(function ($couponId) {
            $storeIds = DB::table('coupon_product')
                ->join('products', 'products.id', '=', 'coupon_product.product_id')
                ->where('coupon_product.coupon_code_id', $couponId)
                ->whereNotNull('products.store_id')
                ->distinct()->pluck('products.store_id');

            if ($storeIds->count() === 1) {
                DB::table('coupon_codes')->where('id', $couponId)->update(['store_id' => $storeIds->first()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupon_codes', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'code']);
            $table->unique('code');
        });
        Schema::table('sms_templates', fn (Blueprint $table) => $table->dropUnique(['store_id', 'type']));
        Schema::table('mail_templates', fn (Blueprint $table) => $table->dropUnique(['store_id', 'type']));

        foreach (['coupon_codes', 'sms_templates', 'mail_templates'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropForeign(['store_id']);
                $table->dropIndex($tableName.'_store_id_index');
                $table->dropColumn('store_id');
            });
        }
    }
};
