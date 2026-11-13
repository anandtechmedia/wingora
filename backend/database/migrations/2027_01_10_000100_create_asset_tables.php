<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assets: categories, the asset registry, employee assignments and maintenance records. Additive only.
 * Composite (id, tenant_id) keys stop cross-tenant links; assets are retired, never deleted, so history is kept.
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

        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('assets', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->string('asset_tag', 40);
            $table->string('name', 120);
            $table->string('serial_number', 120)->nullable();
            $table->string('status', 16)->default('available'); // available | assigned | in_maintenance | retired
            $table->string('condition', 12)->default('good'); // new | good | fair | poor | damaged
            $table->date('purchased_on')->nullable();
            $table->decimal('purchase_cost', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('warranty_ends_on')->nullable();
            $table->text('notes')->nullable();
            // Denormalised from the open assignment for filtering; single-column so it can null out.
            $table->foreignId('current_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'asset_tag']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'current_employee_id']);
            $tenantFk($table, 'category_id', 'asset_categories');
        });

        Schema::create('asset_assignments', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('employee_id');
            $table->date('assigned_on');
            $table->date('due_back_on')->nullable();
            $table->date('returned_on')->nullable();
            $table->string('condition_out', 12);
            $table->string('condition_in', 12)->nullable();
            $table->text('notes')->nullable();
            $table->text('return_notes')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'employee_id', 'returned_on']);
            $table->index(['tenant_id', 'asset_id', 'assigned_on']);
            $tenantFk($table, 'asset_id', 'assets');
            $tenantFk($table, 'employee_id', 'employees');
        });

        Schema::create('asset_maintenance_records', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('asset_id');
            $table->string('kind', 16); // repair | service | inspection | upgrade
            $table->text('description');
            $table->string('vendor', 120)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('status', 12)->default('scheduled'); // scheduled | in_progress | completed | cancelled
            $table->date('scheduled_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'asset_id', 'status']);
            $tenantFk($table, 'asset_id', 'assets');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_records');
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_categories');
    }
};
