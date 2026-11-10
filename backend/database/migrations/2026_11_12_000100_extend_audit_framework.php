<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Centralized audit trail: categories, outcomes, request context, per-chain HMAC hash chains and retention. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->unique()->after('id');
            $table->string('chain', 80)->nullable()->after('public_id');
            $table->unsignedBigInteger('sequence')->nullable()->after('chain');
            $table->string('category', 40)->default('administration')->after('actor_user_id');
            $table->string('outcome', 10)->default('success')->after('event');
            $table->string('actor_type', 20)->nullable()->after('actor_user_id');
            $table->string('subject_public_id', 26)->nullable()->after('subject_id');
            $table->json('metadata')->nullable()->after('new_values');
            $table->string('http_method', 10)->nullable()->after('user_agent');
            $table->string('route', 120)->nullable()->after('http_method');
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();

            $table->unique(['chain', 'sequence']);
            $table->index(['tenant_id', 'category', 'created_at']);
            $table->index(['tenant_id', 'actor_user_id', 'created_at']);
            $table->index(['tenant_id', 'outcome', 'created_at']);
            $table->index('request_id');
            $table->index('subject_public_id');
        });

        Schema::create('audit_chains', function (Blueprint $table) {
            $table->id();
            $table->string('chain', 80)->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->char('last_hash', 64)->nullable();
            $table->unsignedBigInteger('anchor_sequence')->default(0);
            $table->char('anchor_hash', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('audit_retention_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->unsignedInteger('retention_days');
            $table->timestamps();

            $table->unique(['tenant_id', 'category']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('audit_legal_hold')->default(false)->after('mfa_required');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('audit_legal_hold'));
        Schema::dropIfExists('audit_retention_policies');
        Schema::dropIfExists('audit_chains');
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropUnique(['chain', 'sequence']);
            $table->dropIndex(['tenant_id', 'category', 'created_at']);
            $table->dropIndex(['tenant_id', 'actor_user_id', 'created_at']);
            $table->dropIndex(['tenant_id', 'outcome', 'created_at']);
            $table->dropIndex(['request_id']);
            $table->dropIndex(['subject_public_id']);
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'chain', 'sequence', 'category', 'outcome', 'actor_type', 'subject_public_id', 'metadata', 'http_method', 'route', 'prev_hash', 'hash']);
        });
    }
};
