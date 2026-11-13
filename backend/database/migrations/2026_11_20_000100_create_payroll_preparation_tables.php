<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll preparation. Additive only. Money is stored as integer minor units (cents) with an explicit
 * currency; nothing here computes tax or statutory deductions. Every line records where its value came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantFk = function (Blueprint $table, string $column, string $on, bool $cascade = false) {
            $fk = $table->foreign([$column, 'tenant_id'])->references(['id', 'tenant_id'])->on($on);
            $cascade ? $fk->cascadeOnDelete() : $fk->restrictOnDelete();
        };

        Schema::create('salary_structures', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('name', 120);
            $table->string('code', 40);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'legal_entity_id', 'legal_entities');
        });

        Schema::create('salary_components', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('salary_structure_id');
            $table->string('code', 40);
            $table->string('name', 120);
            $table->string('kind', 20);
            $table->string('method', 20);
            $table->bigInteger('amount_minor')->nullable();
            $table->unsignedInteger('rate_basis_points')->nullable();
            $table->string('rule_key', 40)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['salary_structure_id', 'effective_from']);
            $tenantFk($table, 'salary_structure_id', 'salary_structures', true);
        });

        Schema::create('compensation_records', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('salary_structure_id')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('basis', 20);
            $table->string('pay_frequency', 20);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
            $tenantFk($table, 'employee_id', 'employees', true);
            $tenantFk($table, 'legal_entity_id', 'legal_entities');
            $tenantFk($table, 'salary_structure_id', 'salary_structures');
        });

        Schema::create('payroll_calendars', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('name', 120);
            $table->string('pay_frequency', 20);
            $table->date('first_period_start');
            $table->unsignedTinyInteger('pay_date_offset_days')->default(0);
            $table->unsignedTinyInteger('approval_levels')->default(2);
            $table->string('provider', 40)->default('generic_csv');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'legal_entity_id', 'legal_entities');
        });

        Schema::create('payroll_periods', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payroll_calendar_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('pay_date');
            $table->timestamps();
            $table->unique(['payroll_calendar_id', 'start_date']);
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'payroll_calendar_id', 'payroll_calendars', true);
        });

        Schema::create('payroll_runs', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payroll_period_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->char('currency', 3);
            $table->string('type', 20)->default('regular');
            // Set only for regular runs, so the unique index allows one regular run per period.
            $table->unsignedBigInteger('regular_period_id')->nullable()->unique();
            $table->string('status', 20)->default('draft');
            $table->unsignedTinyInteger('approval_levels');
            $table->unsignedTinyInteger('current_level')->default(0);
            $table->unsignedSmallInteger('review_cycle')->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('deductions_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->string('notes', 1000)->nullable();
            $table->string('rejection_note', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'payroll_period_id', 'payroll_periods');
            $tenantFk($table, 'legal_entity_id', 'legal_entities');
        });

        Schema::create('payroll_run_approvals', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payroll_run_id');
            $table->unsignedSmallInteger('review_cycle');
            $table->unsignedTinyInteger('level');
            $table->string('decision', 20);
            $table->foreignId('decided_by')->constrained('users');
            $table->string('note', 1000)->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->unique(['payroll_run_id', 'review_cycle', 'level'], 'payroll_run_approvals_level_unique');
            $table->unique(['payroll_run_id', 'review_cycle', 'decided_by'], 'payroll_run_approvals_approver_unique');
            $tenantFk($table, 'payroll_run_id', 'payroll_runs', true);
        });

        Schema::create('payslips', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payroll_run_id');
            $table->unsignedBigInteger('employee_id');
            $table->char('currency', 3);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('deductions_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->unsignedSmallInteger('days_paid')->default(0);
            $table->unsignedSmallInteger('period_days')->default(0);
            $table->json('snapshot');
            $table->json('issues')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'payroll_run_id', 'payroll_runs', true);
            $tenantFk($table, 'employee_id', 'employees');
        });

        Schema::create('loans', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->char('currency', 3);
            $table->bigInteger('principal_minor');
            $table->bigInteger('installment_minor');
            $table->date('first_deduction_on');
            $table->string('status', 20)->default('active');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'employee_id', 'employees');
        });

        Schema::create('reimbursements', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('category', 40);
            $table->string('description', 1000);
            $table->date('expense_date');
            $table->foreignId('receipt_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->unsignedBigInteger('paid_run_id')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->unique(['id', 'tenant_id']);
            $tenantFk($table, 'employee_id', 'employees');
            $tenantFk($table, 'paid_run_id', 'payroll_runs');
        });

        Schema::create('payroll_lines', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payslip_id');
            $table->unsignedBigInteger('payroll_run_id');
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('kind', 20);
            $table->string('source', 20);
            $table->bigInteger('amount_minor');
            $table->json('basis')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->unsignedBigInteger('loan_id')->nullable();
            $table->unsignedBigInteger('reimbursement_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['payroll_run_id', 'source']);
            $tenantFk($table, 'payslip_id', 'payslips', true);
            $tenantFk($table, 'payroll_run_id', 'payroll_runs', true);
            $tenantFk($table, 'loan_id', 'loans');
            $tenantFk($table, 'reimbursement_id', 'reimbursements');
        });

        Schema::create('loan_repayments', function (Blueprint $table) use ($tenantFk) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('loan_id');
            $table->unsignedBigInteger('payroll_run_id');
            $table->bigInteger('amount_minor');
            $table->timestamps();
            $table->unique(['loan_id', 'payroll_run_id']);
            $tenantFk($table, 'loan_id', 'loans', true);
            $tenantFk($table, 'payroll_run_id', 'payroll_runs');
        });
    }

    public function down(): void
    {
        foreach (['loan_repayments', 'payroll_lines', 'reimbursements', 'loans', 'payslips', 'payroll_run_approvals', 'payroll_runs',
            'payroll_periods', 'payroll_calendars', 'compensation_records', 'salary_components', 'salary_structures'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
