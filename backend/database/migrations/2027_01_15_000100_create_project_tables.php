<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects and timesheets: projects, members, tasks, weekly timesheets and their entries. Additive only.
 * Composite (id, tenant_id) keys stop cross-tenant links. Entries carry employee_id so reports need no joins.
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

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('client', 120)->nullable();
            $table->text('description')->nullable();
            // planned | active | on_hold | completed | cancelled
            $table->string('status', 12)->default('planned');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->decimal('budget_hours', 9, 2)->nullable();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('project_members', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('employee_id');
            $table->timestamps();
            $table->unique(['project_id', 'employee_id']);
            $table->index(['tenant_id', 'employee_id']);
            $tenantFk($table, 'project_id', 'projects');
            $tenantFk($table, 'employee_id', 'employees');
        });

        Schema::create('project_tasks', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->foreignId('assignee_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            // todo | in_progress | done
            $table->string('status', 12)->default('todo');
            $table->date('due_on')->nullable();
            $table->decimal('estimate_hours', 7, 2)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->index(['project_id', 'status']);
            $table->index(['tenant_id', 'assignee_employee_id', 'status'], 'project_tasks_assignee_status_index');
            $tenantFk($table, 'project_id', 'projects');
        });

        Schema::create('timesheets', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->date('week_start');
            // draft | submitted | approved | rejected
            $table->string('status', 12)->default('draft');
            $table->unsignedInteger('total_minutes')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $table->unique(['tenant_id', 'employee_id', 'week_start'], 'timesheets_employee_week_unique');
            $table->index(['tenant_id', 'status', 'week_start']);
            $tenantFk($table, 'employee_id', 'employees');
        });

        Schema::create('timesheet_entries', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('timesheet_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('project_id');
            $table->foreignId('project_task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->date('work_date');
            $table->unsignedSmallInteger('minutes');
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index('timesheet_id');
            $table->index(['project_id', 'work_date']);
            $tenantFk($table, 'timesheet_id', 'timesheets');
            $tenantFk($table, 'employee_id', 'employees');
            $tenantFk($table, 'project_id', 'projects');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_entries');
        Schema::dropIfExists('timesheets');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
