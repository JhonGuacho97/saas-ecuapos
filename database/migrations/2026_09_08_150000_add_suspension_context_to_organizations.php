<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('suspension_reason', 40)->nullable()->after('is_active');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            $table->text('suspension_note')->nullable()->after('suspended_at');
        });

        DB::table('organizations')->where('is_active', false)->update([
            'suspension_reason' => 'ADMINISTRATIVE',
            'suspended_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['suspension_reason', 'suspended_at', 'suspension_note']);
        });
    }
};
