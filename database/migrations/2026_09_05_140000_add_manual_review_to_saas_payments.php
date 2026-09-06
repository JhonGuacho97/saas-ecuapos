<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_payments', function (Blueprint $table) {
            $table->string('proof_path')->nullable()->after('provider_reference');
            $table->timestamp('submitted_at')->nullable()->after('proof_path');
            $table->timestamp('reviewed_at')->nullable()->after('failed_at');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'status', 'submitted_at'], 'saas_payment_review_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('saas_payments', function (Blueprint $table) {
            $table->dropIndex('saas_payment_review_queue_index');
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['proof_path', 'submitted_at', 'reviewed_at', 'reviewed_by']);
        });
    }
};
