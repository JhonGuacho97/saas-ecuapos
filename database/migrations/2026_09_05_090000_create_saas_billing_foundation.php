<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('status')->index();
        });

        Schema::table('saas_plans', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->default(0)->after('name');
            $table->char('currency', 3)->default('USD')->after('price');
            $table->string('billing_interval', 20)->default('monthly')->after('currency');
            $table->unsignedSmallInteger('billing_interval_count')->default(1)->after('billing_interval');
            $table->text('description')->nullable()->after('billing_interval_count');
            $table->unsignedSmallInteger('grace_days')->default(3)->after('trial_days');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('features');
        });

        Schema::table('organization_subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('status');
            $table->boolean('cancel_at_period_end')->default(false)->after('auto_renew');
            $table->timestamp('canceled_at')->nullable()->after('current_period_ends_at');
            $table->timestamp('grace_ends_at')->nullable()->after('canceled_at');
            $table->timestamp('last_payment_at')->nullable()->after('grace_ends_at');
            $table->timestamp('next_billing_at')->nullable()->after('last_payment_at');
            $table->string('payment_provider', 40)->nullable()->after('next_billing_at');
            $table->string('provider_customer_id')->nullable()->after('payment_provider');
            $table->string('provider_subscription_id')->nullable()->after('provider_customer_id');
            $table->text('admin_notes')->nullable()->after('electronic_documents_used');
            $table->index(['status', 'next_billing_at'], 'saas_subscription_billing_index');
        });

        Schema::create('saas_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('organization_subscription_id');
            $table->unsignedBigInteger('saas_plan_id');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('status', 30)->default('PENDING');
            $table->string('method', 40)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_reference')->nullable()->unique();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('organization_subscription_id', 'saas_payment_subscription_fk')
                ->references('id')->on('organization_subscriptions')->cascadeOnDelete();
            $table->foreign('saas_plan_id')->references('id')->on('saas_plans')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'paid_at']);
        });

        Schema::create('saas_subscription_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_subscription_id');
            $table->string('type', 50);
            $table->string('description');
            $table->json('context')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_subscription_id', 'saas_event_subscription_fk')
                ->references('id')->on('organization_subscriptions')->cascadeOnDelete();
            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_subscription_id', 'created_at'], 'saas_event_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_subscription_events');
        Schema::dropIfExists('saas_payments');

        Schema::table('organization_subscriptions', function (Blueprint $table) {
            $table->dropIndex('saas_subscription_billing_index');
            $table->dropColumn([
                'auto_renew', 'cancel_at_period_end', 'canceled_at', 'grace_ends_at',
                'last_payment_at', 'next_billing_at', 'payment_provider',
                'provider_customer_id', 'provider_subscription_id', 'admin_notes',
            ]);
        });

        Schema::table('saas_plans', function (Blueprint $table) {
            $table->dropColumn([
                'price', 'currency', 'billing_interval', 'billing_interval_count',
                'description', 'grace_days', 'sort_order',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
