<?php

namespace Tests\Feature\Time;

use App\Country\RuleCategory;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Models\LeaveLedgerEntry;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Tenancy;
use App\Time\ScheduleResolver;
use App\Time\TimeZones;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Country\CountryTestCase;

class AttendanceLeaveTest extends CountryTestCase
{
    private Tenant $tenant;

    private User $hr;

    private WorkLocation $ny;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->hr = $this->hrAdmin($this->tenant);
        $this->ny = $this->location($this->entity($this->tenant, 'US'), 'US-NY', 'America/New_York');
    }

    /** @return array{0: User, 1: Employee} */
    private function staff(string $role = 'employee', ?WorkLocation $location = null, ?Employee $manager = null, ?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $location ??= $this->ny;
        $user = $this->makeUser($tenant, $role);
        $employee = $this->makeEmployee($tenant);
        $employee->forceFill(['user_id' => $user->id, 'legal_entity_id' => $location->legal_entity_id, 'work_location_id' => $location->id,
            'manager_id' => $manager?->id, 'hire_date' => '2020-01-01', 'status' => 'active'])->saveQuietly();

        return [$user->fresh(), $employee->fresh()];
    }

    private function nineToFive(): array
    {
        $shift = $this->apiAs($this->hr, 'POST', '/api/v1/shifts', ['name' => 'Day', 'code' => 'DAY', 'start_time' => '09:00', 'end_time' => '17:00',
            'break_minutes' => 60, 'late_grace_minutes' => 5, 'early_grace_minutes' => 5])->assertCreated()->json('data.id');
        $schedule = $this->apiAs($this->hr, 'POST', '/api/v1/work-schedules', ['name' => 'Mon–Fri', 'is_default' => true,
            'days' => ['1' => $shift, '2' => $shift, '3' => $shift, '4' => $shift, '5' => $shift, '6' => null, '7' => null]])->assertCreated()->json('data.id');

        return [$shift, $schedule];
    }

    private function leaveType(array $type = [], array $policy = []): string
    {
        $id = $this->apiAs($this->hr, 'POST', '/api/v1/leave-types', $type + ['name' => 'Vacation', 'code' => 'vacation'])->assertCreated()->json('data.id');
        $this->apiAs($this->hr, 'POST', "/api/v1/leave-types/{$id}/policies", $policy + ['effective_from' => '2026-01-01', 'annual_entitlement' => 12, 'accrual' => 'annual',
            'carry_forward_max' => 5, 'carry_forward_expiry_months' => 3])->assertCreated();

        return $id;
    }

    public function test_clock_in_out_uses_the_employee_time_zone_and_computes_late_early_and_overtime(): void
    {
        $this->nineToFive();
        $dubai = $this->location($this->entity($this->tenant, 'AE'), 'AE-DU', 'Asia/Dubai');
        [$user, $employee] = $this->staff(location: $dubai);

        // 05:20 UTC on Monday 2027-03-01 is 09:20 in Dubai: 20 minutes late (grace 5).
        $this->travelTo(CarbonImmutable::parse('2027-03-01 05:20:00', 'UTC'));
        $this->apiAs($user, 'POST', '/api/v1/attendance/clock-in')->assertCreated()
            ->assertJsonPath('data.work_date', '2027-03-01')->assertJsonPath('data.timezone', 'Asia/Dubai')
            ->assertJsonPath('data.clock_in_local', '2027-03-01 09:20')->assertJsonPath('data.late_minutes', 20);
        $this->apiAs($user, 'POST', '/api/v1/attendance/clock-in')->assertStatus(409);

        $this->travelTo(CarbonImmutable::parse('2027-03-01 12:30:00', 'UTC')); // 16:30 local, 30 minutes early.
        $this->apiAs($user, 'POST', '/api/v1/attendance/clock-out')->assertOk()
            ->assertJsonPath('data.early_minutes', 30)->assertJsonPath('data.worked_minutes', 370)->assertJsonPath('data.overtime_minutes', 0);
        $this->apiAs($user, 'POST', '/api/v1/attendance/clock-out')->assertStatus(409);
        $this->apiAs($user, 'POST', '/api/v1/attendance/clock-in')->assertStatus(409);

        // Next day: 21:00 local finish → 3 hours overtime beyond 7 scheduled hours.
        $this->travelTo(CarbonImmutable::parse('2027-03-02 05:00:00', 'UTC'));
        $this->apiAs($user, 'POST', '/api/v1/attendance/clock-in')->assertCreated()->assertJsonPath('data.late_minutes', 0);
        $this->travelTo(CarbonImmutable::parse('2027-03-02 17:00:00', 'UTC'));
        $this->apiAs($user, 'POST', '/api/v1/attendance/clock-out')->assertOk()->assertJsonPath('data.overtime_minutes', 240)->assertJsonPath('data.worked_minutes', 660);
    }

    public function test_work_date_follows_local_midnight_not_utc(): void
    {
        [$user] = $this->staff();
        // 03:30 UTC on 2027-06-02 is still 23:30 on 2027-06-01 in New York.
        $this->travelTo(CarbonImmutable::parse('2027-06-02 03:30:00', 'UTC'));
        $this->apiAs($user, 'POST', '/api/v1/attendance/clock-in')->assertCreated()->assertJsonPath('data.work_date', '2027-06-01');
        $this->apiAs($user, 'GET', '/api/v1/attendance/me')->assertOk()->assertJsonPath('data.today', '2027-06-01')->assertJsonPath('data.record.open', true);
    }

    public function test_overnight_shifts_and_local_times_respect_daylight_saving(): void
    {
        $night = Tenancy::run($this->tenant, fn () => Shift::create(['name' => 'Night', 'code' => 'N', 'start_time' => '22:00', 'end_time' => '06:00']));
        [$s, $e] = ScheduleResolver::window($night, '2027-03-13', 'America/New_York'); // Clocks spring forward on 2027-03-14.
        $this->assertSame(7 * 60, TimeZones::minutesBetween($s, $e));
        [$s, $e] = ScheduleResolver::window($night, '2027-11-06', 'America/New_York'); // Clocks fall back on 2027-11-07.
        $this->assertSame(9 * 60, TimeZones::minutesBetween($s, $e));
        [$s, $e] = ScheduleResolver::window($night, '2027-11-06', 'Asia/Dubai'); // No DST.
        $this->assertSame(8 * 60, TimeZones::minutesBetween($s, $e));

        $this->expectException(ValidationException::class);
        TimeZones::parseLocal('2027-03-14 02:30', 'America/New_York', 'clock_in');
    }

    public function test_hr_corrections_are_audited_and_restricted(): void
    {
        [$user, $employee] = $this->staff();
        $this->travelTo(CarbonImmutable::parse('2027-03-10 18:00:00', 'UTC'));
        $payload = ['employee' => $employee->public_id, 'work_date' => '2027-03-08', 'clock_in' => '2027-03-08 08:55', 'clock_out' => '2027-03-08 17:05', 'reason' => 'Badge reader outage'];

        $this->apiAs($user, 'PUT', '/api/v1/attendance/records', $payload)->assertForbidden();
        $this->apiAs($this->hr, 'PUT', '/api/v1/attendance/records', $payload)->assertOk()->assertJsonPath('data.source', 'manual')->assertJsonPath('data.clock_in_local', '2027-03-08 08:55');
        $this->apiAs($this->hr, 'PUT', '/api/v1/attendance/records', ['clock_in' => '2027-03-08 09:30'] + $payload)->assertOk();
        $this->apiAs($this->hr, 'PUT', '/api/v1/attendance/records', ['clock_in' => '2027-03-14 02:30', 'work_date' => '2027-03-14'] + $payload)->assertUnprocessable()->assertJsonValidationErrors('clock_in');
        $this->apiAs($this->hr, 'PUT', '/api/v1/attendance/records', ['clock_out' => '2027-03-08 08:00'] + $payload)->assertUnprocessable()->assertJsonValidationErrors('clock_out');
        $this->apiAs($this->hr, 'PUT', '/api/v1/attendance/records', ['work_date' => '2027-03-20', 'clock_in' => '2027-03-20 09:00', 'clock_out' => null] + $payload)->assertUnprocessable();

        $audits = AuditLog::query()->withoutGlobalScopes()->where('event', 'attendance.adjusted')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame('Badge reader outage', $audits[1]->metadata['reason']);
        $this->assertNotNull($audits[1]->old_values['clock_in_at'] ?? null);

        [$hrUser] = [$this->hr];
        $hrEmployee = $this->makeEmployee($this->tenant);
        $hrEmployee->forceFill(['user_id' => $hrUser->id, 'work_location_id' => $this->ny->id, 'legal_entity_id' => $this->ny->legal_entity_id])->saveQuietly();
        $this->apiAs($hrUser->fresh(), 'PUT', '/api/v1/attendance/records', ['employee' => $hrEmployee->public_id] + $payload)->assertForbidden();
    }

    public function test_attendance_requests_need_the_right_approver_and_decide_once(): void
    {
        [$managerUser, $manager] = $this->staff('department-manager');
        [$user, $employee] = $this->staff(manager: $manager);
        [$otherManager] = $this->staff('department-manager');
        $this->travelTo(CarbonImmutable::parse('2027-03-10 18:00:00', 'UTC'));

        $id = $this->apiAs($user, 'POST', '/api/v1/attendance/requests', ['work_date' => '2027-03-09', 'clock_in' => '2027-03-09 09:00', 'clock_out' => '2027-03-09 17:00', 'reason' => 'Forgot to clock in'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->apiAs($user, 'POST', '/api/v1/attendance/requests', ['work_date' => '2027-03-09', 'clock_in' => '2027-03-09 09:00', 'reason' => 'Duplicate request'])->assertStatus(409);

        $this->apiAs($user, 'POST', "/api/v1/attendance/requests/{$id}/approve")->assertForbidden();
        $this->apiAs($otherManager, 'POST', "/api/v1/attendance/requests/{$id}/approve")->assertNotFound();
        $this->apiAs($managerUser, 'GET', '/api/v1/attendance/requests?scope=approvals')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_decide', true);
        $this->apiAs($managerUser, 'POST', "/api/v1/attendance/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->apiAs($this->hr, 'POST', "/api/v1/attendance/requests/{$id}/approve")->assertStatus(409);

        $this->apiAs($user, 'GET', '/api/v1/attendance/records?from=2027-03-01&to=2027-03-10')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'request');
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', 'attendance.adjustment_approved')->exists());
        $row = collect($this->apiAs($managerUser, 'GET', '/api/v1/attendance/report?from=2027-03-08&to=2027-03-10')->assertOk()->json('data'))->firstWhere('employee.id', $employee->public_id);
        $this->assertSame([1, 1], [$row['days_present'], $row['days_absent']]);
        $this->apiAs($user, 'GET', '/api/v1/attendance/report')->assertForbidden();
    }

    public function test_leave_application_counts_working_days_and_uses_configured_policies_only(): void
    {
        [$user, $employee] = $this->staff();
        $this->travelTo(CarbonImmutable::parse('2027-02-01 15:00:00', 'UTC'));
        $none = $this->apiAs($this->hr, 'POST', '/api/v1/leave-types', ['name' => 'Unconfigured', 'code' => 'unconfigured'])->json('data.id');
        $this->apiAs($user, 'POST', '/api/v1/leave/requests', ['leave_type' => $none, 'start_date' => '2027-03-01', 'end_date' => '2027-03-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');

        $type = $this->leaveType();
        $calendar = Tenancy::run($this->tenant, function () {
            $c = HolidayCalendar::create(['name' => 'NY', 'country_code' => 'US', 'include_statutory' => false]);
            $h = new Holiday(['date' => '2027-03-03', 'name' => 'Company day']);
            $h->forceFill(['holiday_calendar_id' => $c->id])->save();

            return $c;
        });
        $this->ny->forceFill(['holiday_calendar_id' => $calendar->id])->save();

        $this->apiAs($this->hr, 'POST', "/api/v1/leave-types/{$type}/allocate", ['year' => 2027])->assertOk()->assertJsonPath('data.entries_created', 1);
        $this->apiAs($this->hr, 'POST', "/api/v1/leave-types/{$type}/allocate", ['year' => 2027])->assertOk()->assertJsonPath('data.entries_created', 0);

        // Mon 1 – Sun 7 March: 5 weekdays minus the Wednesday holiday = 4 days.
        $this->apiAs($user, 'POST', '/api/v1/leave/requests/preview', ['start_date' => '2027-03-01', 'end_date' => '2027-03-07'])->assertOk()->assertJsonPath('data.days', 4);
        $id = $this->apiAs($user, 'POST', '/api/v1/leave/requests', ['leave_type' => $type, 'start_date' => '2027-03-01', 'end_date' => '2027-03-07'])
            ->assertCreated()->assertJsonPath('data.days', 4)->json('data.id');
        $this->apiAs($user, 'POST', '/api/v1/leave/requests', ['leave_type' => $type, 'start_date' => '2027-03-05', 'end_date' => '2027-03-08'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->apiAs($user, 'POST', '/api/v1/leave/requests', ['leave_type' => $type, 'start_date' => '2027-04-05', 'end_date' => '2027-04-16'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->apiAs($user, 'POST', '/api/v1/leave/requests', ['leave_type' => $type, 'start_date' => '2027-12-30', 'end_date' => '2028-01-04'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->apiAs($user, 'POST', '/api/v1/leave/requests', ['leave_type' => $type, 'start_date' => '2027-03-06', 'end_date' => '2027-03-07'])->assertUnprocessable();

        $balance = collect($this->apiAs($user, 'GET', '/api/v1/leave/balances?year=2027')->assertOk()->json('data'))->firstWhere('leave_type.id', $type);
        $this->assertEquals(['entitled' => 12, 'pending' => 4, 'available' => 8], array_intersect_key($balance, array_flip(['entitled', 'pending', 'available'])));
        $this->apiAs($this->hr, 'POST', "/api/v1/leave/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $balance = collect($this->apiAs($user, 'GET', '/api/v1/leave/balances?year=2027')->json('data'))->firstWhere('leave_type.id', $type);
        $this->assertEquals([8, 4, 0], [$balance['available'], $balance['used'], $balance['pending']]);

        $this->apiAs($user, 'POST', "/api/v1/leave/requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->apiAs($user, 'POST', "/api/v1/leave/requests/{$id}/cancel")->assertStatus(409);
        $this->assertEquals(12, collect($this->apiAs($user, 'GET', '/api/v1/leave/balances?year=2027')->json('data'))->firstWhere('leave_type.id', $type)['available']);
        $this->assertSame(['allocation', 'deduction', 'reversal'], collect($this->apiAs($user, 'GET', '/api/v1/leave/ledger?year=2027')->json('data'))->pluck('entry_type')->sort()->values()->all());
    }

    public function test_two_step_approvals_are_permission_based_and_concurrency_safe(): void
    {
        [$managerUser, $manager] = $this->staff('department-manager');
        [$user, $employee] = $this->staff(manager: $manager);
        $hrManager = $this->makeUser($this->tenant, 'hr-manager');
        $this->travelTo(CarbonImmutable::parse('2027-02-01 15:00:00', 'UTC'));
        $type = $this->leaveType(['approval_steps' => 2]);
        $this->apiAs($this->hr, 'POST', "/api/v1/leave-types/{$type}/allocate", ['year' => 2027])->assertOk();

        $id = $this->apiAs($user, 'POST', '/api/v1/leave/requests', ['leave_type' => $type, 'start_date' => '2027-03-02', 'end_date' => '2027-03-02', 'half_day' => 'first_half'])
            ->assertCreated()->assertJsonPath('data.days', 0.5)->json('data.id');
        $this->apiAs($user, 'POST', "/api/v1/leave/requests/{$id}/approve")->assertForbidden();
        $this->apiAs($managerUser, 'POST', "/api/v1/leave/requests/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->apiAs($managerUser, 'POST', "/api/v1/leave/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.current_step', 2);
        $this->apiAs($managerUser, 'POST', "/api/v1/leave/requests/{$id}/approve")->assertForbidden();
        $this->apiAs($managerUser, 'GET', '/api/v1/leave/requests?scope=approvals')->assertOk()->assertJsonCount(0, 'data');
        $this->apiAs($hrManager, 'GET', '/api/v1/leave/requests?scope=approvals')->assertOk()->assertJsonCount(1, 'data');

        $this->apiAs($hrManager, 'POST', "/api/v1/leave/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->apiAs($this->hr, 'POST', "/api/v1/leave/requests/{$id}/approve")->assertStatus(409);
        $this->assertSame(1, LeaveLedgerEntry::query()->withoutGlobalScopes()->where('entry_type', 'deduction')->count());
        $this->assertSame(2, AuditLog::query()->withoutGlobalScopes()->where('event', 'leave.request_approved')->count());
    }

    public function test_carry_forward_caps_lapses_and_expires(): void
    {
        [$user] = $this->staff();
        $this->travelTo(CarbonImmutable::parse('2027-01-15 15:00:00', 'UTC'));
        $type = $this->leaveType();
        $this->apiAs($this->hr, 'POST', "/api/v1/leave-types/{$type}/allocate", ['year' => 2026])->assertOk();
        $this->apiAs($this->hr, 'POST', "/api/v1/leave-types/{$type}/carry-forward", ['from_year' => 2027])->assertUnprocessable();
        $this->apiAs($this->hr, 'POST', "/api/v1/leave-types/{$type}/carry-forward", ['from_year' => 2026])->assertOk()->assertJsonPath('data.employees', 1);
        $this->apiAs($this->hr, 'POST', "/api/v1/leave-types/{$type}/carry-forward", ['from_year' => 2026])->assertOk()->assertJsonPath('data.employees', 0);

        $b2026 = collect($this->apiAs($user, 'GET', '/api/v1/leave/balances?year=2026')->json('data'))->firstWhere('leave_type.id', $type);
        $this->assertEquals([7, 0], [$b2026['lapsed'], $b2026['available']]);
        $b2027 = collect($this->apiAs($user, 'GET', '/api/v1/leave/balances?year=2027')->json('data'))->firstWhere('leave_type.id', $type);
        $this->assertEquals([5, 5], [$b2027['carried_forward'], $b2027['available']]);

        $this->travelTo(CarbonImmutable::parse('2027-04-02 15:00:00', 'UTC')); // Carried days expired on 31 March.
        $b2027 = collect($this->apiAs($user, 'GET', '/api/v1/leave/balances?year=2027')->json('data'))->firstWhere('leave_type.id', $type);
        $this->assertEquals([5, 0], [$b2027['expired'], $b2027['available']]);
    }

    public function test_leave_types_follow_country_rules_and_tenant_isolation(): void
    {
        $this->approvedRule('US', null, RuleCategory::Leave, ['leave_types' => [['key' => 'sick', 'name' => 'Sick', 'paid' => true, 'unit' => 'days', 'entitlement' => null]]], '2020-01-01');
        $this->apiAs($this->hr, 'POST', '/api/v1/leave-types', ['name' => 'Sick', 'code' => 'sick', 'country_code' => 'US', 'rule_key' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('rule_key');
        $us = $this->apiAs($this->hr, 'POST', '/api/v1/leave-types', ['name' => 'Sick', 'code' => 'sick', 'country_code' => 'US', 'rule_key' => 'sick'])->assertCreated()->json('data.id');
        $mx = $this->apiAs($this->hr, 'POST', '/api/v1/leave-types', ['name' => 'Vacaciones', 'code' => 'vacaciones', 'country_code' => 'MX'])->assertCreated()->json('data.id');

        [$user] = $this->staff();
        $this->travelTo(CarbonImmutable::parse('2027-02-01 15:00:00', 'UTC'));
        $types = collect($this->apiAs($user, 'GET', '/api/v1/leave/balances')->assertOk()->json('data'))->pluck('leave_type.id');
        $this->assertTrue($types->contains($us));
        $this->assertFalse($types->contains($mx));
        $this->apiAs($user, 'POST', '/api/v1/leave/requests', ['leave_type' => $mx, 'start_date' => '2027-03-01', 'end_date' => '2027-03-01'])->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
        $this->apiAs($user, 'POST', '/api/v1/leave-types', ['name' => 'X', 'code' => 'x'])->assertForbidden();

        $other = $this->makeTenant();
        $otherHr = $this->hrAdmin($other);
        $this->apiAs($otherHr, 'GET', '/api/v1/leave-types')->assertOk()->assertJsonCount(0, 'data');
        $this->apiAs($otherHr, 'PATCH', "/api/v1/leave-types/{$us}", ['name' => 'Hijack'])->assertNotFound();
        $this->apiAs($otherHr, 'POST', "/api/v1/leave-types/{$us}/allocate", ['year' => 2027])->assertNotFound();

        $type = $this->leaveType();
        $this->apiAs($this->hr, 'POST', "/api/v1/leave-types/{$type}/allocate", ['year' => 2027]);
        $req = $this->apiAs($user, 'POST', '/api/v1/leave/requests', ['leave_type' => $type, 'start_date' => '2027-03-01', 'end_date' => '2027-03-01'])->assertCreated()->json('data.id');
        $this->apiAs($otherHr, 'GET', "/api/v1/leave/requests/{$req}")->assertNotFound();
        $this->apiAs($otherHr, 'POST', "/api/v1/leave/requests/{$req}/approve")->assertNotFound();
        $this->apiAs($otherHr, 'GET', '/api/v1/leave/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->apiAs($otherHr, 'GET', '/api/v1/attendance/records')->assertOk()->assertJsonCount(0, 'data');
        [$foreignUser, $foreign] = $this->staff(location: $this->location($this->entity($other, 'US'), 'US-NY', 'America/New_York'), tenant: $other);
        $this->apiAs($this->hr, 'GET', "/api/v1/leave/balances?employee={$foreign->public_id}")->assertNotFound();
        $this->apiAs($this->hr, 'PUT', '/api/v1/attendance/records', ['employee' => $foreign->public_id, 'work_date' => '2027-01-29', 'clock_in' => '2027-01-29 09:00', 'reason' => 'Cross tenant'])->assertNotFound();
    }
}
