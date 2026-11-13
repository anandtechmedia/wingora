<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Versioned, effective-dated country/subdivision rules with an append-only approval history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_sets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->char('country_code', 2);
            $table->string('subdivision_code', 6)->nullable();
            $table->string('category', 40);
            $table->string('key', 60);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['country_code', 'subdivision_code', 'category', 'key']);
            $table->index(['category', 'country_code']);
            $table->foreign('country_code')->references('code')->on('countries');
            $table->foreign('subdivision_code')->references('code')->on('subdivisions');
        });

        Schema::create('rule_versions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('rule_set_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('payload');
            $table->string('change_summary', 500);
            $table->string('legal_reference', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['rule_set_id', 'version']);
            $table->index(['rule_set_id', 'status', 'effective_from']);
        });

        Schema::create('rule_version_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_version_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comment', 1000)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at');

            $table->index(['rule_version_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_version_events');
        Schema::dropIfExists('rule_versions');
        Schema::dropIfExists('rule_sets');
    }
};
