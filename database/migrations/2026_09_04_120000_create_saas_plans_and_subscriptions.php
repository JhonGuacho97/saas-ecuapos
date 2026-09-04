<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_stores')->nullable();
            $table->unsignedInteger('max_warehouses')->nullable();
            $table->unsignedInteger('max_electronic_documents')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('organization_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->unique();
            $table->unsignedBigInteger('saas_plan_id');
            $table->string('status', 30);
            $table->timestamp('starts_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->unsignedInteger('electronic_documents_used')->default(0);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('saas_plan_id')->references('id')->on('saas_plans')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->index(['status', 'trial_ends_at']);
        });

        Schema::create('saas_usage_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_subscription_id');
            $table->string('metric', 60);
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->timestamp('reserved_at');
            $table->timestamps();

            $table->foreign('organization_subscription_id', 'saas_usage_subscription_fk')
                ->references('id')->on('organization_subscriptions')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->unique(['metric', 'source_type', 'source_id'], 'saas_usage_source_unique');
            $table->index(['organization_subscription_id', 'metric'], 'saas_usage_metric_index');
        });

        $now = now();
        $legacyPlanId = DB::table('saas_plans')->insertGetId([
            'code' => 'legacy',
            'name' => 'Instalación heredada',
            'trial_days' => 0,
            'max_users' => null,
            'max_stores' => null,
            'max_warehouses' => null,
            'max_electronic_documents' => null,
            'features' => json_encode(['*']),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('saas_plans')->insert([
            'code' => 'trial',
            'name' => 'Prueba de 14 días',
            'trial_days' => 14,
            'max_users' => 1,
            'max_stores' => 1,
            'max_warehouses' => 1,
            'max_electronic_documents' => 10,
            'features' => json_encode(['*']),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $subscriptions = DB::table('organizations')->orderBy('id')->get(['id', 'created_at'])
            ->map(fn ($organization) => [
                'organization_id' => $organization->id,
                'saas_plan_id' => $legacyPlanId,
                'status' => 'ACTIVE',
                'starts_at' => $organization->created_at ?: $now,
                'trial_ends_at' => null,
                'current_period_starts_at' => null,
                'current_period_ends_at' => null,
                'electronic_documents_used' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        if ($subscriptions) {
            DB::table('organization_subscriptions')->insert($subscriptions);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_usage_reservations');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('saas_plans');
    }
};
