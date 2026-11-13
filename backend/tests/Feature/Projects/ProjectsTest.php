<?php

namespace Tests\Feature\Projects;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Tests\Feature\Security\SecurityTestCase;

class ProjectsTest extends SecurityTestCase
{
    private Tenant $tenant;

    private User $admin;

    private User $manager;

    private User $employee;

    private User $other;

    private Employee $managerRecord;

    private Employee $employeeRecord;

    private Employee $otherRecord;

    private const WEEK = '2027-03-01'; // a Monday

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeUser($this->tenant, 'hr-administrator');
        $this->manager = $this->makeUser($this->tenant, 'department-manager');
        $this->employee = $this->makeUser($this->tenant, 'employee');
        $this->other = $this->makeUser($this->tenant, 'employee');
        $this->makeEmployee($this->tenant, null, $this->admin);
        $this->managerRecord = $this->makeEmployee($this->tenant, null, $this->manager);
        $this->employeeRecord = $this->makeEmployee($this->tenant, null, $this->employee);
        $this->otherRecord = $this->makeEmployee($this->tenant, null, $this->other);
        Tenancy::run($this->tenant, fn () => $this->employeeRecord->forceFill(['manager_id' => $this->managerRecord->id])->save());
    }

    private function project(array $overrides = []): array
    {
        return $this->apiAs($this->admin, 'POST', '/api/v1/projects', $overrides + [
            'code' => 'WEB-1', 'name' => 'Website relaunch', 'client' => 'Acme', 'status' => 'active', 'starts_on' => '2027-01-01', 'budget_hours' => '10',
        ])->assertCreated()->json('data');
    }

    private function withMember(array $project, Employee $employee): array
    {
        return $this->apiAs($this->admin, 'POST', "/api/v1/projects/{$project['id']}/members", ['employee_id' => $employee->public_id])->assertOk()->json('data');
    }

    private function entries(array $project, ?string $task = null, int $minutes = 240): array
    {
        return [
            ['project_id' => $project['id'], 'task_id' => $task, 'work_date' => '2027-03-01', 'minutes' => $minutes, 'note' => 'Build'],
            ['project_id' => $project['id'], 'task_id' => null, 'work_date' => '2027-03-02', 'minutes' => 120],
        ];
    }

    public function test_projects_tasks_timesheets_approval_and_report(): void
    {
        $project = $this->withMember($this->project(['manager_id' => $this->managerRecord->public_id]), $this->employeeRecord);
        $this->assertSame(2, $project['members_count']);

        $task = $this->apiAs($this->manager, 'POST', "/api/v1/projects/{$project['id']}/tasks", [
            'title' => 'Landing page', 'assignee_id' => $this->employeeRecord->public_id, 'due_on' => '2027-03-05', 'estimate_hours' => '8',
        ])->assertCreated()->json('data');
        $this->assertSame('Landing page', $this->apiAs($this->employee, 'GET', '/api/v1/projects/tasks/mine')->assertOk()->json('data.0.title'));
        $this->apiAs($this->employee, 'GET', '/api/v1/projects')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.can_manage', false);
        $moved = $this->apiAs($this->employee, 'PATCH', "/api/v1/projects/tasks/{$task['id']}", ['status' => 'in_progress', 'version' => $task['version']])->assertOk()->json('data');
        $this->apiAs($this->employee, 'PATCH', "/api/v1/projects/tasks/{$task['id']}", ['title' => 'Mine now', 'version' => $moved['version']])->assertForbidden();

        $mine = $this->apiAs($this->employee, 'GET', '/api/v1/timesheets/mine?week=2027-03-03')->assertOk()->json('data');
        $this->assertSame([self::WEEK, 0, 'WEB-1'], [$mine['timesheet']['week_start'], $mine['timesheet']['version'], $mine['projects'][0]['code']]);
        $sheet = $this->apiAs($this->employee, 'PUT', '/api/v1/timesheets/mine/'.self::WEEK, ['version' => 0, 'entries' => $this->entries($project, $task['id'])])->assertOk()->json('data');
        $this->assertSame([360, 'draft', 1], [$sheet['total_minutes'], $sheet['status'], $sheet['version']]);
        $this->apiAs($this->employee, 'PUT', '/api/v1/timesheets/mine/'.self::WEEK, ['version' => 0, 'entries' => []])->assertStatus(409);

        $submitted = $this->apiAs($this->employee, 'POST', "/api/v1/timesheets/{$sheet['id']}/submit", ['version' => 1])->assertOk()->json('data');
        $this->apiAs($this->employee, 'PUT', '/api/v1/timesheets/mine/'.self::WEEK, ['version' => $submitted['version'], 'entries' => []])->assertStatus(422);
        $this->apiAs($this->manager, 'GET', '/api/v1/timesheets/approvals')->assertOk()->assertJsonPath('meta.total', 1);
        $this->apiAs($this->employee, 'POST', "/api/v1/timesheets/{$sheet['id']}/approve", ['version' => $submitted['version']])->assertForbidden();
        $this->apiAs($this->manager, 'POST', "/api/v1/timesheets/{$sheet['id']}/reject", ['version' => $submitted['version']])->assertStatus(422)->assertJsonValidationErrors('note');
        $rejected = $this->apiAs($this->manager, 'POST', "/api/v1/timesheets/{$sheet['id']}/reject", ['version' => $submitted['version'], 'note' => 'Split by task'])->assertOk()->json('data');
        $this->assertSame(['rejected', 'Split by task'], [$rejected['status'], $rejected['decision_note']]);

        $resaved = $this->apiAs($this->employee, 'PUT', '/api/v1/timesheets/mine/'.self::WEEK, ['version' => $rejected['version'], 'entries' => $this->entries($project, $task['id'], 300)])->assertOk()->json('data');
        $again = $this->apiAs($this->employee, 'POST', "/api/v1/timesheets/{$sheet['id']}/submit", ['version' => $resaved['version']])->assertOk()->json('data');
        $this->apiAs($this->admin, 'GET', "/api/v1/projects/{$project['id']}/report")->assertOk()->assertJsonPath('data.totals.pending_minutes', 420);
        $this->apiAs($this->manager, 'POST', "/api/v1/timesheets/{$sheet['id']}/approve", ['version' => $again['version']])->assertOk()->assertJsonPath('data.status', 'approved');

        $report = $this->apiAs($this->manager, 'GET', "/api/v1/projects/{$project['id']}/report")->assertOk()->json('data');
        $this->assertSame([420, 0, 600, 70.0], [$report['totals']['approved_minutes'], $report['totals']['pending_minutes'], $report['totals']['budget_minutes'], (float) $report['totals']['budget_used_percent']]);
        $this->assertSame($this->employeeRecord->public_id, $report['by_employee'][0]['employee']['id']);
        $this->assertSame([self::WEEK, 420], [$report['by_week'][0]['week_start'], $report['by_week'][0]['approved_minutes']]);
        $this->assertSame(['Landing page', 300], [$report['by_task'][0]['task']['title'], $report['by_task'][0]['approved_minutes']]);
        $this->apiAs($this->employee, 'GET', "/api/v1/projects/{$project['id']}/report")->assertForbidden();

        foreach (['projects.project_created', 'projects.project_member_created', 'projects.project_task_created', 'projects.timesheet_updated'] as $event) {
            $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', $event)->where('category', 'projects')->exists(), $event);
        }
    }

    public function test_timesheet_validation(): void
    {
        $project = $this->withMember($this->project(), $this->employeeRecord);
        $closed = $this->withMember($this->project(['code' => 'OLD', 'status' => 'completed']), $this->employeeRecord);
        $foreign = $this->project(['code' => 'HR']);
        $put = fn (array $entries, string $week = self::WEEK) => $this->apiAs($this->employee, 'PUT', "/api/v1/timesheets/mine/{$week}", ['version' => 0, 'entries' => $entries]);

        $put([], '2027-03-02')->assertStatus(422)->assertJsonValidationErrors('week');
        $put([['project_id' => $foreign['id'], 'work_date' => '2027-03-01', 'minutes' => 60]])->assertStatus(422)->assertJsonValidationErrors('entries.0.project_id');
        $put([['project_id' => $closed['id'], 'work_date' => '2027-03-01', 'minutes' => 60]])->assertStatus(422)->assertJsonValidationErrors('entries.0.project_id');
        $put([['project_id' => $project['id'], 'work_date' => '2027-03-08', 'minutes' => 60]])->assertStatus(422)->assertJsonValidationErrors('entries.0.work_date');
        $put([['project_id' => $project['id'], 'work_date' => '2027-03-01', 'minutes' => 1000], ['project_id' => $project['id'], 'work_date' => '2027-03-01', 'minutes' => 500]])
            ->assertStatus(422)->assertJsonValidationErrors('entries.1.minutes');
        $otherTask = $this->apiAs($this->admin, 'POST', "/api/v1/projects/{$foreign['id']}/tasks", ['title' => 'Policies'])->assertCreated()->json('data');
        $put([['project_id' => $project['id'], 'task_id' => $otherTask['id'], 'work_date' => '2027-03-01', 'minutes' => 60]])->assertStatus(422)->assertJsonValidationErrors('entries.0.task_id');
        $this->apiAs($this->admin, 'POST', "/api/v1/projects/{$project['id']}/tasks", ['title' => 'X', 'assignee_id' => $this->otherRecord->public_id])->assertStatus(422)->assertJsonValidationErrors('assignee_id');

        $empty = $put([])->assertOk()->json('data');
        $this->apiAs($this->employee, 'POST', "/api/v1/timesheets/{$empty['id']}/submit", ['version' => $empty['version']])->assertStatus(422);
        $this->apiAs($this->admin, 'POST', '/api/v1/projects', ['code' => 'WEB-1', 'name' => 'Dup'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->apiAs($this->admin, 'PATCH', "/api/v1/projects/{$project['id']}", ['ends_on' => '2026-12-31', 'version' => $project['version']])->assertStatus(422)->assertJsonValidationErrors('ends_on');
        $this->apiAs($this->admin, 'PATCH', "/api/v1/projects/{$project['id']}", ['name' => 'Stale', 'version' => 99])->assertStatus(409);
    }

    public function test_permissions(): void
    {
        $project = $this->withMember($this->project(), $this->employeeRecord);
        $this->apiAs($this->employee, 'POST', '/api/v1/projects', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->apiAs($this->employee, 'POST', "/api/v1/projects/{$project['id']}/tasks", ['title' => 'X'])->assertForbidden();
        $this->apiAs($this->other, 'GET', "/api/v1/projects/{$project['id']}")->assertNotFound();
        $this->apiAs($this->other, 'GET', '/api/v1/projects')->assertOk()->assertJsonPath('meta.total', 0);
        $this->apiAs($this->employee, 'GET', '/api/v1/timesheets/approvals')->assertForbidden();

        $sheet = $this->apiAs($this->other, 'PUT', '/api/v1/timesheets/mine/'.self::WEEK, ['version' => 0, 'entries' => []])->assertOk()->json('data');
        $this->apiAs($this->employee, 'GET', "/api/v1/timesheets/{$sheet['id']}")->assertNotFound();
        $this->apiAs($this->employee, 'POST', "/api/v1/timesheets/{$sheet['id']}/submit", ['version' => 1])->assertNotFound();

        // The department manager approves direct reports only; HR approves anyone but never their own sheet.
        $this->withMember($project, $this->otherRecord);
        $otherSheet = $this->apiAs($this->other, 'PUT', '/api/v1/timesheets/mine/'.self::WEEK, ['version' => 1, 'entries' => $this->entries($project)])->assertOk()->json('data');
        $submitted = $this->apiAs($this->other, 'POST', "/api/v1/timesheets/{$otherSheet['id']}/submit", ['version' => $otherSheet['version']])->assertOk()->json('data');
        $this->apiAs($this->manager, 'GET', '/api/v1/timesheets/approvals')->assertOk()->assertJsonPath('meta.total', 0);
        $this->apiAs($this->manager, 'POST', "/api/v1/timesheets/{$otherSheet['id']}/approve", ['version' => $submitted['version']])->assertForbidden();
        $this->apiAs($this->admin, 'POST', "/api/v1/timesheets/{$otherSheet['id']}/approve", ['version' => $submitted['version']])->assertOk();

        $this->withMember($project, Employee::query()->withoutGlobalScopes()->where('user_id', $this->admin->id)->firstOrFail());
        $own = $this->apiAs($this->admin, 'PUT', '/api/v1/timesheets/mine/'.self::WEEK, ['version' => 0, 'entries' => $this->entries($project)])->assertOk()->json('data');
        $ownSubmitted = $this->apiAs($this->admin, 'POST', "/api/v1/timesheets/{$own['id']}/submit", ['version' => $own['version']])->assertOk()->json('data');
        $this->apiAs($this->admin, 'POST', "/api/v1/timesheets/{$own['id']}/approve", ['version' => $ownSubmitted['version']])->assertForbidden();
    }

    public function test_tenant_isolation_and_plan_gate(): void
    {
        $project = $this->project();
        $otherTenant = $this->makeTenant();
        $outsider = $this->makeUser($otherTenant, 'hr-administrator');
        $this->makeEmployee($otherTenant, null, $outsider);
        $this->apiAs($outsider, 'GET', "/api/v1/projects/{$project['id']}")->assertNotFound();
        $this->apiAs($outsider, 'GET', '/api/v1/projects')->assertOk()->assertJsonPath('meta.total', 0);
        $this->apiAs($outsider, 'POST', "/api/v1/projects/{$project['id']}/members", ['employee_id' => $this->employeeRecord->public_id])->assertNotFound();
        $theirs = $this->apiAs($outsider, 'POST', '/api/v1/projects', ['code' => 'WEB-1', 'name' => 'Same code, other tenant', 'status' => 'active'])->assertCreated()->json('data');
        $this->apiAs($outsider, 'POST', "/api/v1/projects/{$theirs['id']}/members", ['employee_id' => $this->employeeRecord->public_id])->assertStatus(422);

        Subscription::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['plan_id' => Plan::query()->where('code', 'starter')->value('id')]);
        $this->apiAs($this->admin, 'GET', '/api/v1/projects')->assertStatus(402);
        $this->apiAs($this->employee, 'GET', '/api/v1/timesheets/mine')->assertStatus(402);
    }
}
