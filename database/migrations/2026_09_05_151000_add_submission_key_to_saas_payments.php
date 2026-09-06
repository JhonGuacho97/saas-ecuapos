<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_payments', function (Blueprint $table) {
            $table->uuid('submission_key')->nullable()->after('provider_reference')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('saas_payments', function (Blueprint $table) {
            $table->dropUnique(['submission_key']);
            $table->dropColumn('submission_key');
        });
    }
};
