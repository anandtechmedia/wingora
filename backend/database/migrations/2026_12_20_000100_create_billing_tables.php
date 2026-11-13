<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SaaS billing: plans, one subscription per organization, invoices mirrored from Stripe, processed webhook
 * events (duplicate protection) and monthly usage counters. Additive only. No card data is stored anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('employee_limit')->nullable(); // null = unlimited
            $table->unsignedInteger('ai_requests_monthly')->default(0);
            $table->json('features');
            $table->unsignedSmallInteger('trial_days')->default(14);
            // {"USD": {"monthly": {"amount": 4900, "stripe_price": "price_..."}, "annual": {...}}, ...}; amounts are minor units, for display.
            $table->json('prices');
            $table->unsignedSmallInteger('rank')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 20); // trialing | active | past_due | unpaid | canceled
            $table->string('provider', 10); // trial | manual | stripe
            $table->string('billing_interval', 10)->nullable(); // monthly | annual
            $table->char('currency', 3)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('past_due_since')->nullable();
            $table->foreignId('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('pending_interval', 10)->nullable();
            $table->timestamp('plan_change_at')->nullable();
            $table->string('stripe_customer_id', 64)->nullable()->unique();
            $table->string('stripe_subscription_id', 64)->nullable()->unique();
            $table->string('stripe_price_id', 64)->nullable();
            $table->unsignedBigInteger('stripe_synced_at')->nullable(); // `created` of the last applied Stripe event
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['status', 'trial_ends_at']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id', 64)->unique();
            $table->string('number', 64)->nullable();
            $table->string('status', 20); // draft | open | paid | void | uncollectible
            $table->char('currency', 3);
            $table->bigInteger('amount_due');
            $table->bigInteger('amount_paid')->default(0);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('hosted_invoice_url', 1000)->nullable();
            $table->string('invoice_pdf_url', 1000)->nullable();
            $table->unsignedBigInteger('stripe_synced_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'issued_at']);
        });

        // Every verified webhook event, keyed by the provider's event id; the unique key is what stops repeats.
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 10);
            $table->string('event_id', 100);
            $table->string('type', 100);
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 20); // processed | ignored
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['provider', 'event_id']);
            $table->index('created_at');
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 40);
            $table->char('period', 7); // YYYY-MM (UTC)
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'metric', 'period']);
        });

        $now = now();
        $plans = [
            ['starter', 'Starter', 25, 0, ['attendance', 'leave', 'helpdesk', 'documents'], 1],
            ['growth', 'Growth', 250, 500, ['attendance', 'leave', 'payroll', 'recruitment', 'onboarding', 'helpdesk', 'documents'], 2],
            ['enterprise', 'Enterprise', null, 5000, ['attendance', 'leave', 'payroll', 'recruitment', 'onboarding', 'helpdesk', 'documents'], 3],
        ];
        foreach ($plans as [$code, $name, $employees, $ai, $features, $rank]) {
            // Prices are deliberately empty: platform staff set real prices and Stripe price ids.
            DB::table('plans')->insert(['public_id' => (string) Str::ulid(), 'code' => $code, 'name' => $name, 'employee_limit' => $employees,
                'ai_requests_monthly' => $ai, 'features' => json_encode($features), 'trial_days' => 14, 'prices' => '{}', 'rank' => $rank,
                'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Organizations that existed before billing keep full access on a manually managed Enterprise subscription.
        $enterprise = DB::table('plans')->where('code', 'enterprise')->value('id');
        DB::table('tenants')->orderBy('id')->each(function ($tenant) use ($enterprise, $now) {
            DB::table('subscriptions')->insert(['public_id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'plan_id' => $enterprise,
                'status' => 'active', 'provider' => 'manual', 'created_at' => $now, 'updated_at' => $now]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
