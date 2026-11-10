<?php

namespace Tests\Concerns;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Security\RoleCatalog;
use App\Support\Tenancy;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

trait BuildsSecurityFixtures
{
    public const PASSWORD = 'Correct-Horse-9!';

    protected function makeTenant(array $attributes = []): Tenant
    {
        $tenant = Tenant::factory()->create($attributes);
        RoleCatalog::syncTenantRoles($tenant);

        return $tenant;
    }

    /** Privileged users get a confirmed MFA secret unless $mfa is false. */
    protected function makeUser(?Tenant $tenant, string $role, bool $mfa = true, array $attributes = []): User
    {
        $create = fn () => User::factory()->create($attributes + ['tenant_id' => $tenant?->id]);
        $user = $tenant ? Tenancy::run($tenant, $create) : $create();

        $roleId = Role::query()->withoutGlobalScopes()->where('tenant_id', $tenant?->id)->where('key', $role)->value('id');
        if (! $roleId && ! $tenant) {
            RoleCatalog::syncPlatformRoles();
            $roleId = Role::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('key', $role)->value('id');
        }
        $user->roles()->attach($roleId, ['created_at' => now()]);
        $user->flushPermissions();

        if ($mfa && $user->isPrivileged()) {
            $user->forceFill(['mfa_secret' => (new Google2FA)->generateSecretKey(32), 'mfa_confirmed_at' => now()])->saveQuietly();
        }

        return $user->fresh();
    }

    protected function makeDepartment(Tenant $tenant, string $name, ?Employee $manager = null): Department
    {
        return Tenancy::run($tenant, function () use ($name, $manager) {
            $department = new Department(['name' => $name]);
            $department->forceFill(['manager_employee_id' => $manager?->id])->save();

            return $department;
        });
    }

    protected function makeEmployee(Tenant $tenant, ?Department $department = null, ?User $user = null, array $sensitive = []): Employee
    {
        return Tenancy::run($tenant, function () use ($department, $user, $sensitive) {
            $employee = new Employee([
                'employee_number' => 'E-'.Str::upper(Str::random(6)),
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'work_email' => fake()->safeEmail(),
                'job_title' => 'Analyst',
            ]);
            $employee->forceFill(['department_id' => $department?->id, 'user_id' => $user?->id] + $sensitive)->save();

            return $employee;
        });
    }

    protected function makeCompensation(Employee $employee, string $salary = '85000.00'): EmployeeCompensation
    {
        return Tenancy::run($employee->tenant, function () use ($employee, $salary) {
            $comp = new EmployeeCompensation(['currency' => 'USD', 'base_salary' => $salary, 'pay_frequency' => 'monthly', 'effective_date' => '2026-01-01']);
            $comp->forceFill(['employee_id' => $employee->id])->save();

            return $comp;
        });
    }

    protected function totp(User $user): string
    {
        return (new Google2FA)->getCurrentOtp($user->fresh()->mfa_secret);
    }

    /** Sends the request as the first-party SPA (Sanctum stateful) would. */
    protected function spa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
    }
}
