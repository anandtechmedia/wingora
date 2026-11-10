<?php

namespace Tests\Feature\Employees;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Country\CountryTestCase;

class EmployeeManagementTest extends CountryTestCase
{
    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->hr = $this->hrAdmin($this->tenant);
    }

    private function employeeUser(?Employee $employee = null, string $role = 'employee'): array
    {
        $user = $this->makeUser($this->tenant, $role);
        $employee ??= $this->makeEmployee($this->tenant);
        $employee->forceFill(['user_id' => $user->id])->saveQuietly();

        return [$user->fresh(), $employee->fresh()];
    }

    public function test_hr_creates_and_updates_an_employee_with_history_and_audit(): void
    {
        $entity = $this->entity($this->tenant, 'US');
        $location = $this->location($entity, 'US-CA', 'America/Los_Angeles');
        $this->employmentTypes('US', null, ['full_time', 'part_time'], '2020-01-01');
        $dept = $this->makeDepartment($this->tenant, 'Finance');
        $other = $this->makeDepartment($this->tenant, 'Sales');

        $id = $this->apiAs($this->hr, 'POST', '/api/v1/employees', [
            'employee_number' => 'E-100', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'work_email' => 'ana@example.com',
            'department_id' => $dept->public_id, 'legal_entity_id' => $entity->public_id, 'work_location_id' => $location->public_id,
            'employment_type' => 'full_time', 'hire_date' => '2024-03-01',
        ])->assertCreated()->assertJsonPath('data.department.name', 'Finance')->assertJsonPath('data.abilities.update', true)->json('data.id');

        $this->apiAs($this->hr, 'PATCH', "/api/v1/employees/{$id}", ['department_id' => $other->public_id, 'effective_date' => '2025-01-01', 'note' => 'Reorg'])
            ->assertOk()->assertJsonPath('data.department.name', 'Sales');
        $this->apiAs($this->hr, 'PATCH', "/api/v1/employees/{$id}", ['employment_type' => 'contractor'])->assertUnprocessable()->assertJsonValidationErrors('employment_type');

        $history = $this->apiAs($this->hr, 'GET', "/api/v1/employees/{$id}/history")->assertOk()->json('data');
        $this->assertSame(['transfer', 'hire'], array_column($history, 'change_type'));
        $this->assertSame(['from' => 'Finance', 'to' => 'Sales'], $history[0]['changes']['department']);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', 'employee.updated')->exists());

        $this->apiAs($this->hr, 'POST', '/api/v1/employees', ['employee_number' => 'E-100', 'first_name' => 'X', 'last_name' => 'Y'])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_number');
    }

    public function test_manager_cycles_and_cross_tenant_references_are_rejected(): void
    {
        $a = $this->makeEmployee($this->tenant);
        $b = $this->makeEmployee($this->tenant);
        $foreign = $this->makeEmployee($this->makeTenant());

        $this->apiAs($this->hr, 'PATCH', "/api/v1/employees/{$b->public_id}", ['manager_id' => $a->public_id])->assertOk();
        $this->apiAs($this->hr, 'PATCH', "/api/v1/employees/{$a->public_id}", ['manager_id' => $b->public_id])->assertUnprocessable()->assertJsonValidationErrors('manager_id');
        $this->apiAs($this->hr, 'PATCH', "/api/v1/employees/{$a->public_id}", ['manager_id' => $foreign->public_id])->assertUnprocessable()->assertJsonValidationErrors('manager_id');
        $this->apiAs($this->hr, 'GET', "/api/v1/employees/{$foreign->public_id}")->assertNotFound();
        $this->apiAs($this->hr, 'GET', '/api/v1/employees?manager='.$foreign->public_id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_directory_search_filters_sorting_and_pagination(): void
    {
        $dept = $this->makeDepartment($this->tenant, 'Ops');
        $mx = $this->entity($this->tenant, 'MX');
        foreach (['Zed', 'Amy', 'Bob'] as $i => $name) {
            $e = $this->makeEmployee($this->tenant, $i === 0 ? $dept : null);
            $e->forceFill(['last_name' => $name, 'legal_entity_id' => $i === 1 ? $mx->id : null, 'status' => $i === 2 ? 'terminated' : 'active'])->saveQuietly();
        }
        $this->makeEmployee($this->makeTenant());

        $this->apiAs($this->hr, 'GET', '/api/v1/employees?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.last_name', 'Amy');
        $this->apiAs($this->hr, 'GET', '/api/v1/employees?sort=-name')->assertJsonPath('data.0.last_name', 'Zed');
        $this->apiAs($this->hr, 'GET', '/api/v1/employees?search=bo')->assertJsonCount(1, 'data');
        $this->apiAs($this->hr, 'GET', '/api/v1/employees?department='.$dept->public_id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Zed');
        $this->apiAs($this->hr, 'GET', '/api/v1/employees?country=MX')->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Amy');
        $this->apiAs($this->hr, 'GET', '/api/v1/employees?status=terminated')->assertJsonCount(1, 'data');
        $this->apiAs($this->hr, 'GET', '/api/v1/employees?sort=salary')->assertUnprocessable();
    }

    public function test_personal_details_are_limited_to_self_and_hr(): void
    {
        $dept = $this->makeDepartment($this->tenant, 'Eng');
        [$self, $employee] = $this->employeeUser($this->makeEmployee($this->tenant, $dept));
        [$manager, $managerEmployee] = $this->employeeUser(null, 'department-manager');
        $dept->forceFill(['manager_employee_id' => $managerEmployee->id])->save();
        [$peer] = $this->employeeUser();

        $this->apiAs($self, 'PATCH', "/api/v1/employees/{$employee->public_id}/personal", ['personal_email' => 'me@home.test', 'mobile_phone' => '+1 555 0100'])->assertOk();
        $this->apiAs($this->hr, 'GET', "/api/v1/employees/{$employee->public_id}/personal")->assertOk()->assertJsonPath('data.personal_email', 'me@home.test');
        $this->apiAs($manager, 'GET', "/api/v1/employees/{$employee->public_id}")->assertOk()->assertJsonPath('data.abilities.view_personal', false);
        $this->apiAs($manager, 'GET', "/api/v1/employees/{$employee->public_id}/personal")->assertForbidden();
        $this->apiAs($manager, 'GET', "/api/v1/employees/{$employee->public_id}/emergency-contacts")->assertForbidden();
        $this->apiAs($peer, 'GET', "/api/v1/employees/{$employee->public_id}")->assertForbidden();
        $this->apiAs($self, 'PATCH', "/api/v1/employees/{$employee->public_id}", ['job_title' => 'CEO'])->assertForbidden();
        $this->apiAs($self, 'GET', "/api/v1/employees/{$employee->public_id}/sensitive")->assertForbidden();

        $raw = Tenancy::run($this->tenant, fn () => Employee::query()->toBase()->where('id', $employee->id)->value('personal_email'));
        $this->assertNotSame('me@home.test', $raw);
        $this->assertStringNotContainsString('me@home.test', (string) AuditLog::query()->withoutGlobalScopes()->pluck('new_values')->implode(' '));
    }

    public function test_disciplinary_records_are_hidden_from_the_employee_and_managers(): void
    {
        [$self, $employee] = $this->employeeUser();
        $manager = $this->makeUser($this->tenant, 'department-manager');

        $this->apiAs($this->hr, 'POST', "/api/v1/employees/{$employee->public_id}/records", [
            'type' => 'disciplinary', 'severity' => 'written_warning', 'title' => 'Late', 'body' => 'Repeated lateness', 'occurred_on' => '2026-01-10',
        ])->assertCreated();
        $this->apiAs($this->hr, 'POST', "/api/v1/employees/{$employee->public_id}/records", ['type' => 'note', 'severity' => 'final_warning', 'title' => 'x', 'body' => 'y', 'occurred_on' => '2026-01-10'])
            ->assertUnprocessable();
        $this->apiAs($this->hr, 'GET', "/api/v1/employees/{$employee->public_id}/records")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Repeated lateness');

        $this->apiAs($self, 'GET', "/api/v1/employees/{$employee->public_id}/records")->assertForbidden();
        $this->apiAs($self, 'GET', "/api/v1/employees/{$employee->public_id}")->assertOk()->assertJsonPath('data.abilities.view_records', false);
        $this->apiAs($manager, 'GET', "/api/v1/employees/{$employee->public_id}/records")->assertForbidden();

        [$hrSelf, $hrEmployee] = $this->employeeUser(null, 'hr-administrator');
        $this->apiAs($hrSelf, 'GET', "/api/v1/employees/{$hrEmployee->public_id}/records")->assertForbidden();
        $this->assertNotSame('Repeated lateness', EmployeeRecord::query()->withoutGlobalScopes()->toBase()->value('body'));
    }

    public function test_emergency_contacts_keep_a_single_primary(): void
    {
        [$self, $employee] = $this->employeeUser();
        $url = "/api/v1/employees/{$employee->public_id}/emergency-contacts";
        $this->apiAs($self, 'POST', $url, ['name' => 'Luis', 'relationship' => 'spouse', 'phone' => '+52 55 1234 5678', 'is_primary' => true])->assertCreated();
        $this->apiAs($self, 'POST', $url, ['name' => 'Eva', 'relationship' => 'parent', 'phone' => '+52 55 0000 0000', 'is_primary' => true])->assertCreated();
        $this->apiAs($self, 'POST', $url, ['name' => 'Bad', 'relationship' => 'boss', 'phone' => 'call me'])->assertUnprocessable()->assertJsonValidationErrors(['relationship', 'phone']);
        $contacts = $this->apiAs($this->hr, 'GET', $url)->assertOk()->json('data');
        $this->assertSame([true, false], array_column($contacts, 'is_primary'));
        $this->assertSame('Eva', $contacts[0]['name']);
    }

    public function test_policies_are_published_and_acknowledged(): void
    {
        [$self, $employee] = $this->employeeUser();
        $id = $this->apiAs($this->hr, 'POST', '/api/v1/policies', ['title' => 'Code of conduct', 'version' => '1.0', 'effective_date' => '2026-01-01'])->assertCreated()->json('data.id');
        $this->apiAs($self, 'GET', '/api/v1/policies')->assertOk()->assertJsonCount(0, 'data');
        $this->apiAs($self, 'POST', "/api/v1/policies/{$id}/acknowledge")->assertStatus(409);
        $this->apiAs($self, 'POST', '/api/v1/policies', ['title' => 'x', 'version' => '1', 'effective_date' => '2026-01-01'])->assertForbidden();
        $this->apiAs($this->hr, 'POST', "/api/v1/policies/{$id}/publish")->assertOk();
        $this->apiAs($self, 'POST', "/api/v1/policies/{$id}/acknowledge")->assertOk();
        $this->apiAs($self, 'POST', "/api/v1/policies/{$id}/acknowledge")->assertOk();
        $this->apiAs($this->hr, 'GET', "/api/v1/employees/{$employee->public_id}/policies")->assertOk()->assertJsonPath('data.0.title', 'Code of conduct');
        $this->assertNotNull($this->apiAs($this->hr, 'GET', "/api/v1/employees/{$employee->public_id}/policies")->json('data.0.acknowledged_at'));
        $this->apiAs($this->hr, 'GET', '/api/v1/policies')->assertJsonPath('data.0.acknowledgements_count', 1);
    }

    public function test_documents_list_respects_file_policy(): void
    {
        [$self, $employee] = $this->employeeUser();
        $manager = $this->makeUser($this->tenant, 'hr-manager');
        $this->apiAs($self, 'POST', '/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('passport.pdf', "%PDF-1.4\n%%EOF"), 'category' => 'identity', 'employee_id' => $employee->public_id])->assertCreated();
        $this->apiAs($self, 'GET', "/api/v1/employees/{$employee->public_id}/documents")->assertOk()->assertJsonCount(1, 'data');
        $this->apiAs($manager, 'GET', "/api/v1/employees/{$employee->public_id}/documents")->assertOk()->assertJsonCount(0, 'data');
        $this->apiAs($this->hr, 'GET', "/api/v1/employees/{$employee->public_id}/documents")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_csv_import_dry_run_and_all_or_nothing_commit(): void
    {
        $this->makeDepartment($this->tenant, 'Finance');
        $csv = fn (string $body) => UploadedFile::fake()->createWithContent('staff.csv', "employee_number,first_name,last_name,department,manager_employee_number,status\n".$body);
        $bad = $csv("E-1,Ana,Ruiz,Finance,,active\nE-2,Omar,,Nowhere,,active\nE-1,Dup,Row,,,active\n");

        $dry = $this->apiAs($this->hr, 'POST', '/api/v1/employees/import', ['file' => $bad, 'dry_run' => true])->assertOk()->json('data');
        $this->assertSame([3, 1, 2], [$dry['rows'], $dry['valid'], $dry['invalid']]);
        $this->assertSame([3, 4], array_column($dry['errors'], 'line'));
        $this->apiAs($this->hr, 'POST', '/api/v1/employees/import', ['file' => $csv("E-1,Ana,Ruiz,Finance,,active\nE-2,Omar,,,,active\n")])->assertUnprocessable();
        $this->assertSame(0, Tenancy::run($this->tenant, fn () => Employee::query()->count()));

        $this->apiAs($this->hr, 'POST', '/api/v1/employees/import', ['file' => $csv("E-1,Ana,Ruiz,Finance,,active\nE-2,Omar,Haddad,,E-1,active\n")])->assertCreated()->assertJsonPath('data.created', 2);
        $this->apiAs($this->hr, 'GET', '/api/v1/employees?search=Haddad')->assertJsonPath('data.0.manager.name', 'Ana Ruiz');
        $this->apiAs($this->makeUser($this->tenant, 'hr-manager'), 'POST', '/api/v1/employees/import', ['file' => $csv("E-9,A,B,,,active\n")])->assertForbidden();
        $this->apiAs($this->hr, 'POST', '/api/v1/employees/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "name,salary\nA,1\n")])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_csv_export_is_scoped_formula_safe_and_audited(): void
    {
        $e = $this->makeEmployee($this->tenant, null, null, ['national_id' => 'SECRET-ID']);
        $e->forceFill(['first_name' => '=HYPERLINK("x")'])->saveQuietly();
        $this->makeEmployee($this->makeTenant());

        $csv = $this->apiAs($this->hr, 'GET', '/api/v1/employees/export')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('SECRET-ID', $csv);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', 'export.completed')->exists());
        [$self] = $this->employeeUser();
        $this->apiAs($self, 'GET', '/api/v1/employees/export')->assertForbidden();
    }

    public function test_designations_and_departments_are_managed_by_hr_only(): void
    {
        [$self] = $this->employeeUser();
        $this->apiAs($this->hr, 'POST', '/api/v1/designations', ['name' => 'Senior Analyst'])->assertCreated();
        $this->apiAs($this->hr, 'POST', '/api/v1/designations', ['name' => 'Senior Analyst'])->assertUnprocessable();
        $this->apiAs($self, 'POST', '/api/v1/designations', ['name' => 'Chief'])->assertForbidden();
        $this->apiAs($self, 'GET', '/api/v1/designations')->assertOk()->assertJsonCount(1, 'data');
        $this->apiAs($this->hr, 'POST', '/api/v1/departments', ['name' => 'Legal'])->assertCreated();
        $this->apiAs($self, 'POST', '/api/v1/departments', ['name' => 'Other'])->assertForbidden();
    }
}
