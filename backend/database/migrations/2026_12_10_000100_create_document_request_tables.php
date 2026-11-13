<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document requests (employment verification letters, salary certificates, ...). Additive only.
 * Answers and rejection reasons are encrypted at rest; delivered files live in the private `documents` file category.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantFk = function (Blueprint $table, string $column, string $on) {
            $name = $table->getTable().'_'.$column.'_tenant_fk';
            $name = strlen($name) > 64 ? substr($name, 0, 55).'_'.substr(md5($name), 0, 8) : $name;
            $table->foreign([$column, 'tenant_id'], $name)->references(['id', 'tenant_id'])->on($on)->restrictOnDelete();
        };

        Schema::create('document_templates', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('delivery', 10); // generated | upload
            $table->mediumText('body')->nullable();
            $table->json('fields');
            $table->boolean('requires_manager')->default(true);
            $table->boolean('includes_salary')->default(false);
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'legal_entity_id', 'legal_entities');
        });

        Schema::create('document_requests', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('document_template_id');
            $table->unsignedBigInteger('employee_id');
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->mediumText('answers');
            $table->string('status', 20);
            $table->boolean('manager_step')->default(false);
            // Snapshot of the template flag, so later template edits cannot change who may decide or download.
            $table->boolean('includes_salary')->default(false);
            $table->foreignId('manager_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('stored_file_id')->nullable()->constrained('stored_files')->restrictOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'requester_id']);
            $tenantFk($table, 'document_template_id', 'document_templates');
            $tenantFk($table, 'employee_id', 'employees');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
        Schema::dropIfExists('document_templates');
    }
};
