<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('id')
                ->constrained('stores')->cascadeOnUpdate()->nullOnDelete();
            $table->index('store_id');
            $table->unique(['store_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_settings', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'key']);
            $table->dropForeign(['store_id']);
            $table->dropIndex('sms_settings_store_id_index');
            $table->dropColumn('store_id');
        });
    }
};
