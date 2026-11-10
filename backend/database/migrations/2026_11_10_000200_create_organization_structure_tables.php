<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedBigInteger('manager_employee_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'manager_employee_id']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('employee_number', 40);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('work_email')->nullable();
            $table->string('job_title', 160)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('national_id')->nullable();
            $table->text('bank_account_number')->nullable();
            $table->text('date_of_birth')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'employee_number']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('manager_employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::create('employee_compensations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->decimal('base_salary', 15, 2);
            $table->string('pay_frequency', 20);
            $table->date('effective_date');
            $table->timestamps();

            $table->index(['tenant_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_compensations');
        Schema::table('departments', fn (Blueprint $table) => $table->dropForeign(['manager_employee_id']));
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
    }
};
