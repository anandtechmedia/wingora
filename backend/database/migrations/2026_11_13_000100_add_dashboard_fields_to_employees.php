<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. Employment dates power headcount metrics; `birthday_md` / `hire_md` (MM-DD, no year) let the
 * database find upcoming birthdays and anniversaries without decrypting `date_of_birth`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('hire_date')->nullable()->after('status');
            $table->date('termination_date')->nullable()->after('hire_date');
            $table->char('hire_md', 5)->nullable()->after('termination_date');
            $table->char('birthday_md', 5)->nullable()->after('hire_md');

            $table->index(['tenant_id', 'hire_date']);
            $table->index(['tenant_id', 'termination_date']);
            $table->index(['tenant_id', 'birthday_md']);
            $table->index(['tenant_id', 'hire_md']);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'hire_date']);
            $table->dropIndex(['tenant_id', 'termination_date']);
            $table->dropIndex(['tenant_id', 'birthday_md']);
            $table->dropIndex(['tenant_id', 'hire_md']);
            $table->dropColumn(['hire_date', 'termination_date', 'hire_md', 'birthday_md']);
        });
    }
};
