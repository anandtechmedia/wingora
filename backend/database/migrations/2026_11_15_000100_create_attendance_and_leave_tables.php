<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance and leave. Additive only. Instants are stored in UTC; work dates are local dates in the
 * employee's time zone. Composite tenant foreign keys keep cross-tenant links impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('work_location_id');
            $table->unique(['id', 'tenant_id'], 'employees_id_tenant_unique');
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 40);
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('late_grace_minutes')->default(0);
            $table->unsignedSmallInteger('early_grace_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_after_minutes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['id', 'tenant_id']);
        });

        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('days');
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('work_schedule_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from']);
            $table->foreign(['employee_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('employees')->cascadeOnDelete();
            $table->foreign(['work_schedule_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('work_schedules');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->string('timezone', 64);
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            // Equals employee_id while the record is open; the unique index allows one open record per employee.
            $table->unsignedBigInteger('open_employee_id')->nullable()->unique();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('source', 20)->default('clock');
            $table->string('note', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index(['tenant_id', 'work_date']);
            $table->foreign(['employee_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('employees')->cascadeOnDelete();
            $table->foreign(['shift_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('shifts');
        });

        Schema::create('attendance_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['employee_id', 'work_date']);
            $table->foreign(['employee_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('employees')->cascadeOnDelete();
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 40);
            $table->char('country_code', 2)->nullable();
            $table->string('rule_key', 40)->nullable();
            $table->boolean('is_paid')->default(true);
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('requires_document')->default(false);
            $table->unsignedTinyInteger('approval_steps')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['id', 'tenant_id']);
            $table->foreign('country_code')->references('code')->on('countries');
        });

        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('annual_entitlement', 6, 2);
            $table->string('accrual', 20)->default('annual');
            $table->boolean('prorate_new_joiners')->default(true);
            $table->decimal('carry_forward_max', 6, 2)->default(0);
            $table->unsignedSmallInteger('carry_forward_expiry_months')->nullable();
            $table->boolean('allow_negative')->default(false);
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();
            $table->unsignedSmallInteger('min_notice_days')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['leave_type_id', 'effective_from']);
            $table->foreign(['leave_type_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('leave_types')->cascadeOnDelete();
            $table->foreign(['legal_entity_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('legal_entities');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('half_day', 20)->nullable();
            $table->decimal('days', 6, 2);
            $table->string('reason', 1000)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->unsignedBigInteger('document_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['employee_id', 'start_date']);
            $table->foreign(['employee_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('employees')->cascadeOnDelete();
            $table->foreign(['leave_type_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('leave_types');
            $table->foreign('document_id')->references('id')->on('stored_files')->nullOnDelete();
        });

        Schema::create('leave_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->string('decision', 20);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 1000)->nullable();
            $table->timestamp('decided_at');
            $table->unique(['leave_request_id', 'step']);
        });

        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedSmallInteger('period_year');
            $table->string('entry_type', 20);
            $table->decimal('amount', 7, 2);
            $table->date('effective_on');
            $table->date('expires_on')->nullable();
            $table->foreignId('leave_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['employee_id', 'leave_type_id', 'period_year']);
            $table->foreign(['employee_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('employees')->cascadeOnDelete();
            $table->foreign(['leave_type_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('leave_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger_entries');
        Schema::dropIfExists('leave_request_approvals');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_policies');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('attendance_requests');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('work_schedules');
        Schema::dropIfExists('shifts');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_id_tenant_unique');
            $table->dropColumn('timezone');
        });
    }
};
