<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance: objectives and OKRs, review cycles and reviews, 360-degree feedback and one-to-one meetings. Additive only.
 * Review answers, feedback and meeting notes are encrypted at rest. Composite (id, tenant_id) keys stop cross-tenant links.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantFk = function (Blueprint $table, string $column, string $on, string $onDelete = 'restrict') {
            $name = $table->getTable().'_'.$column.'_tenant_fk';
            $name = strlen($name) > 64 ? substr($name, 0, 55).'_'.substr(md5($name), 0, 8) : $name;
            $fk = $table->foreign([$column, 'tenant_id'], $name)->references(['id', 'tenant_id'])->on($on);
            $onDelete === 'cascade' ? $fk->cascadeOnDelete() : $fk->restrictOnDelete();
        };

        Schema::create('performance_review_cycles', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('self_due_on')->nullable();
            $table->date('manager_due_on')->nullable();
            $table->string('status', 10)->default('draft'); // draft | open | closed
            $table->json('questions');
            $table->unsignedTinyInteger('rating_scale')->default(5);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'status', 'starts_on']);
        });

        Schema::create('performance_objectives', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('review_cycle_id')->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('kind', 10); // objective | okr
            $table->string('status', 12)->default('active'); // draft | active | completed | cancelled
            $table->unsignedTinyInteger('progress')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'employee_id', 'status']);
            $tenantFk($table, 'employee_id', 'employees');
            $tenantFk($table, 'review_cycle_id', 'performance_review_cycles');
        });
        Schema::table('performance_objectives', function (Blueprint $table) use ($tenantFk) {
            $tenantFk($table, 'parent_id', 'performance_objectives');
        });

        Schema::create('performance_key_results', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('objective_id');
            $table->string('title', 200);
            $table->decimal('start_value', 14, 2)->default(0);
            $table->decimal('target_value', 14, 2);
            $table->decimal('current_value', 14, 2)->default(0);
            $table->string('unit', 20)->nullable();
            $table->unsignedTinyInteger('weight')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'objective_id', 'performance_objectives', 'cascade');
        });

        Schema::create('performance_check_ins', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('objective_id');
            $table->unsignedBigInteger('key_result_id')->nullable();
            $table->decimal('value', 14, 2)->nullable();
            $table->unsignedTinyInteger('progress');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'objective_id', 'created_at']);
            $tenantFk($table, 'objective_id', 'performance_objectives', 'cascade');
            // Removing a key result keeps its check-ins as history.
            $table->foreign('key_result_id')->references('id')->on('performance_key_results')->nullOnDelete();
        });

        Schema::create('performance_reviews', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('review_cycle_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('manager_employee_id')->nullable();
            $table->string('status', 16); // pending_self | pending_manager | completed | acknowledged
            $table->mediumText('self_answers')->nullable();
            $table->mediumText('manager_answers')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('self_submitted_at')->nullable();
            $table->timestamp('manager_submitted_at')->nullable();
            $table->foreignId('manager_submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->unique(['review_cycle_id', 'employee_id']);
            $table->index(['tenant_id', 'employee_id', 'status']);
            $tenantFk($table, 'review_cycle_id', 'performance_review_cycles');
            $tenantFk($table, 'employee_id', 'employees');
            $tenantFk($table, 'manager_employee_id', 'employees');
        });

        Schema::create('performance_feedback', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('review_id');
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('relationship', 16); // peer | direct_report | manager | other
            $table->boolean('anonymous')->default(true);
            $table->string('status', 10)->default('requested'); // requested | submitted | declined
            $table->mediumText('answers')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['review_id', 'reviewer_id']);
            $table->index(['tenant_id', 'reviewer_id', 'status']);
            $tenantFk($table, 'review_id', 'performance_reviews', 'cascade');
        });

        Schema::create('performance_meetings', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('manager_employee_id');
            $table->unsignedBigInteger('employee_id');
            $table->timestamp('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->text('agenda')->nullable();
            $table->mediumText('shared_notes')->nullable();
            $table->mediumText('private_notes')->nullable(); // manager-only
            $table->json('action_items')->nullable();
            $table->string('status', 10)->default('scheduled'); // scheduled | completed | cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['tenant_id', 'employee_id', 'scheduled_at']);
            $table->index(['tenant_id', 'manager_employee_id', 'scheduled_at'], 'performance_meetings_manager_scheduled_index');
            $tenantFk($table, 'manager_employee_id', 'employees');
            $tenantFk($table, 'employee_id', 'employees');
        });
    }

    public function down(): void
    {
        foreach (['performance_meetings', 'performance_feedback', 'performance_reviews', 'performance_check_ins', 'performance_key_results', 'performance_objectives', 'performance_review_cycles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
