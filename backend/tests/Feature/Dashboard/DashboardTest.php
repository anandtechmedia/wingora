<?php

namespace Tests\Feature\Dashboard;

use App\Models\Employee;
use App\Models\Tenant;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Country\CountryTestCase;

class DashboardTest extends CountryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    }

    private function staff(Tenant $tenant, array $attributes, ?int $entityId = null, $department = null): Employee
    {
        $employee = $this->makeEmployee($tenant, $department);
        Tenancy::run($tenant, fn () => $employee->forceFill($attributes + ['legal_entity_id' => $entityId])->save());

        return $employee->fresh();
    }

    public function test_hr_sees_aggregated_metrics_for_the_whole_organization(): void
    {
        $tenant = $this->makeTenant();
        $us = $this->entity($tenant, 'US');
        $this->staff($tenant, ['hire_date' => '2026-03-02', 'date_of_birth' => '1990-06-20'], $us->id);
        $this->staff($tenant, ['hire_date' => '2021-06-25'], $us->id);
        $this->staff($tenant, ['hire_date' => '2020-01-10', 'termination_date' => '2026-04-30', 'status' => 'terminated'], $us->id);

        $response = $this->apiAs($this->hrAdmin($tenant), 'GET', '/api/v1/dashboard?from=2026-01-01&to=2026-06-15')->assertOk()
            ->assertJsonPath('data.scope', 'organization')
            ->assertJsonPath('data.widgets.total_employees.value', 3)
            ->assertJsonPath('data.widgets.active_employees.value', 2)
            ->assertJsonPath('data.widgets.new_joiners.value', 1)
            ->assertJsonPath('data.widgets.upcoming_birthdays.items.0.date', '2026-06-20')
            ->assertJsonPath('data.widgets.upcoming_birthdays.items.0.in_days', 5)
            ->assertJsonPath('data.widgets.upcoming_anniversaries.items.0.years', 5)
            ->assertJsonPath('data.widgets.pending_leave_approvals.state', 'unavailable')
            ->assertJsonPath('data.widgets.recruitment_pipeline.module', 'recruitment');

        $series = collect($response->json('data.widgets.workforce_trends.series'))->keyBy('month');
        $this->assertCount(6, $series);
        $this->assertSame(['month' => '2026-03', 'headcount' => 3, 'hires' => 1, 'exits' => 0], $series['2026-03']);
        $this->assertSame(['month' => '2026-05', 'headcount' => 2, 'hires' => 0, 'exits' => 0], $series['2026-05']);
        $this->assertSame(1, $series['2026-04']['exits']);
    }

    public function test_payload_contains_no_sensitive_employee_fields(): void
    {
        $tenant = $this->makeTenant();
        $this->staff($tenant, ['date_of_birth' => '1987-06-16', 'national_id' => '123-45-6789', 'bank_account_number' => 'DE89370400440532013000']);

        $body = $this->apiAs($this->hrAdmin($tenant), 'GET', '/api/v1/dashboard')->assertOk()->getContent();
        foreach (['1987', '123-45-6789', 'DE89370400440532013000', 'date_of_birth', 'national_id', 'work_email'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
        $this->assertSame(['employee_id', 'name', 'date', 'in_days'], array_keys(json_decode($body, true)['data']['widgets']['upcoming_birthdays']['items'][0]));
        $this->assertSame('06-16', DB::table('employees')->value('birthday_md'));
    }

    public function test_tenant_a_never_counts_or_filters_by_tenant_b(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $this->staff($a, []);
        foreach (range(1, 3) as $_) {
            $this->staff($b, ['date_of_birth' => '1990-06-16']);
        }
        $foreign = $this->entity($b, 'MX');
        $hr = $this->hrAdmin($a);

        $this->apiAs($hr, 'GET', '/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.widgets.total_employees.value', 1)
            ->assertJsonCount(0, 'data.widgets.upcoming_birthdays.items');
        $this->apiAs($hr, 'GET', '/api/v1/dashboard?legal_entity='.$foreign->public_id)->assertUnprocessable()->assertJsonValidationErrors('legal_entity');
    }

    public function test_department_manager_counts_only_managed_departments(): void
    {
        $tenant = $this->makeTenant();
        $managerUser = $this->makeUser($tenant, 'department-manager');
        $manager = $this->makeEmployee($tenant, null, $managerUser);
        $mine = $this->makeDepartment($tenant, 'Sales', $manager);
        $other = $this->makeDepartment($tenant, 'Finance');
        $this->staff($tenant, [], null, $mine);
        $this->staff($tenant, [], null, $mine);
        $this->staff($tenant, ['date_of_birth' => '1990-06-16'], null, $other);

        $this->apiAs($managerUser, 'GET', '/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.scope', 'departments')
            // Two in the managed department plus the manager's own record (EmployeeAccess: departments + self).
            ->assertJsonPath('data.widgets.total_employees.value', 3)
            ->assertJsonCount(0, 'data.widgets.upcoming_birthdays.items')
            ->assertJsonMissingPath('data.widgets.pending_leave_approvals');
    }

    public function test_employee_and_payroll_roles_without_employee_scope_get_no_widgets(): void
    {
        $tenant = $this->makeTenant();
        $this->staff($tenant, []);
        $employee = $this->makeUser($tenant, 'employee');
        $this->makeEmployee($tenant, null, $employee);

        $this->apiAs($employee, 'GET', '/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.scope', 'none')
            ->assertExactJson(['data' => $this->apiAs($employee, 'GET', '/api/v1/dashboard')->json('data')])
            ->assertJsonPath('data.widgets', []);
    }

    public function test_country_and_legal_entity_filters(): void
    {
        $tenant = $this->makeTenant();
        $us = $this->entity($tenant, 'US');
        $mx = $this->entity($tenant, 'MX');
        $this->staff($tenant, [], $us->id);
        $this->staff($tenant, [], $mx->id);
        $this->staff($tenant, [], $mx->id);
        $hr = $this->hrAdmin($tenant);

        $this->apiAs($hr, 'GET', '/api/v1/dashboard?country=MX')->assertOk()->assertJsonPath('data.widgets.total_employees.value', 2);
        $this->apiAs($hr, 'GET', '/api/v1/dashboard?legal_entity='.$us->public_id)->assertOk()
            ->assertJsonPath('data.widgets.total_employees.value', 1)->assertJsonPath('data.filters.legal_entity', $us->public_id);
        $this->apiAs($hr, 'GET', '/api/v1/dashboard?country=MX&legal_entity='.$us->public_id)->assertOk()->assertJsonPath('data.widgets.total_employees.value', 0);
    }

    public function test_date_range_is_validated(): void
    {
        $tenant = $this->makeTenant();
        $hr = $this->hrAdmin($tenant);

        $this->apiAs($hr, 'GET', '/api/v1/dashboard?from=2026-06-01&to=2026-05-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->apiAs($hr, 'GET', '/api/v1/dashboard?from=2023-01-01&to=2026-06-01')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->apiAs($hr, 'GET', '/api/v1/dashboard?country=ZZ')->assertUnprocessable()->assertJsonValidationErrors('country');
        $this->apiAs($hr, 'GET', '/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.filters.from', '2025-07-01')->assertJsonPath('data.filters.to', '2026-06-15')
            ->assertJsonCount(12, 'data.widgets.workforce_trends.series');
    }

    public function test_unauthenticated_and_platform_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $platform = $this->makeUser(null, 'platform-administrator');
        $this->assertContains($this->apiAs($platform, 'GET', '/api/v1/dashboard')->status(), [401, 403, 404]);
    }

    public function test_upcoming_lists_are_capped_and_skip_inactive_staff(): void
    {
        $tenant = $this->makeTenant();
        foreach (range(1, 12) as $_) {
            $this->staff($tenant, ['date_of_birth' => '1991-06-18']);
        }
        $this->staff($tenant, ['date_of_birth' => '1991-06-16', 'status' => 'terminated']);

        $items = $this->apiAs($this->hrAdmin($tenant), 'GET', '/api/v1/dashboard')->assertOk()->json('data.widgets.upcoming_birthdays.items');
        $this->assertCount(8, $items);
        $this->assertSame([3], array_values(array_unique(array_column($items, 'in_days'))));
    }
}
