<?php

namespace Tests\Feature\Onboarding;

use App\Country\RuleCategory;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OnboardingTaskReminder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Country\CountryTestCase;

class OnboardingTest extends CountryTestCase
{
    private Tenant $tenant;

    private LegalEntity $us;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('security.files.disk'));
        $this->tenant = $this->makeTenant();
        $this->us = $this->entity($this->tenant, 'US');
        $this->hr = $this->makeUser($this->tenant, 'hr-administrator');
        $this->approvedRule('US', null, RuleCategory::Compliance, ['requirements' => [
            ['key' => 'form_i9', 'name' => 'Employment eligibility verification (Form I-9)', 'description' => null, 'reference' => null],
        ]], '2026-01-01');
    }

    /** @return array{0: User, 1: Employee} */
    private function person(string $role, ?Employee $manager = null, ?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $user = $this->makeUser($tenant, $role);
        $employee = $this->makeEmployee($tenant, null, $user);
        $employee->forceFill(['legal_entity_id' => $tenant->is($this->tenant) ? $this->us->id : null, 'manager_id' => $manager?->id])->saveQuietly();

        return [$user->fresh(), $employee->fresh()];
    }

    /** @return array{plan: array, employee: User, manager: User} */
    private function started(string $start): array
    {
        [$mgrUser, $mgr] = $this->person('department-manager');
        [$empUser, $emp] = $this->person('employee', $mgr);
        $docs = $this->apiAs($this->hr, 'GET', '/api/v1/onboarding/document-requirements?legal_entity_id='.$this->us->public_id)->assertOk()->json('data');
        $t = $this->apiAs($this->hr, 'POST', '/api/v1/onboarding/templates', ['name' => 'US starter', 'legal_entity_id' => $this->us->public_id])->assertCreated()->json('data');
        $tasks = [
            ['stage' => 'pre_boarding', 'title' => $docs[0]['name'], 'assignee' => 'employee', 'due_offset_days' => -2, 'document_key' => $docs[0]['key'], 'document_name' => $docs[0]['name']],
            ['stage' => 'first_day', 'title' => 'Introduce the team', 'assignee' => 'manager', 'due_offset_days' => 0],
            ['stage' => 'first_week', 'title' => 'Enroll in benefits', 'assignee' => 'hr', 'due_offset_days' => 5],
        ];
        foreach ($tasks as $task) {
            $this->apiAs($this->hr, 'POST', "/api/v1/onboarding/templates/{$t['id']}/tasks", $task)->assertCreated();
        }
        $plan = $this->apiAs($this->hr, 'POST', '/api/v1/onboarding/plans', ['employee_id' => $emp->public_id, 'template_id' => $t['id'], 'start_date' => $start])
            ->assertCreated()->json('data');

        return ['plan' => $plan, 'employee' => $empUser, 'manager' => $mgrUser];
    }

    public function test_document_requirements_come_from_the_country_rules(): void
    {
        $docs = $this->apiAs($this->hr, 'GET', '/api/v1/onboarding/document-requirements?legal_entity_id='.$this->us->public_id)->assertOk();
        $this->assertSame(['form_i9'], array_column($docs->json('data'), 'key'));
        $this->assertSame('country_rules.compliance', $docs->json('meta.source'));
        $mx = $this->entity($this->tenant, 'MX');
        $this->assertSame([], $this->apiAs($this->hr, 'GET', '/api/v1/onboarding/document-requirements?legal_entity_id='.$mx->public_id)->json('data'));
        $this->apiAs($this->makeUser($this->tenant, 'employee'), 'GET', '/api/v1/onboarding/document-requirements?legal_entity_id='.$this->us->public_id)->assertForbidden();
    }

    public function test_responsibilities_documents_and_progress(): void
    {
        ['plan' => $plan, 'employee' => $emp, 'manager' => $mgr] = $this->started('2027-03-01');
        $tasks = collect($plan['tasks'])->keyBy('assignee');
        $this->assertSame(['2027-02-27', '2027-03-01', '2027-03-06'], collect($plan['tasks'])->pluck('due_on')->all());
        $this->assertSame(['total' => 3, 'done' => 0, 'overdue' => 0, 'percent' => 0], $plan['progress']);

        $this->assertSame([$tasks['employee']['id']], array_column($this->apiAs($emp, 'GET', '/api/v1/onboarding/my-tasks')->json('data'), 'id'));
        $this->assertSame([$tasks['manager']['id']], array_column($this->apiAs($mgr, 'GET', '/api/v1/onboarding/my-tasks')->json('data'), 'id'));
        $this->apiAs($emp, 'POST', "/api/v1/onboarding/tasks/{$tasks['manager']['id']}/complete")->assertForbidden();
        $this->apiAs($emp, 'POST', "/api/v1/onboarding/tasks/{$tasks['employee']['id']}/complete")->assertUnprocessable();

        $pdf = fn () => UploadedFile::fake()->createWithContent('i9.pdf', "%PDF-1.4\nI-9\n%%EOF");
        $this->apiAs($emp, 'POST', "/api/v1/onboarding/tasks/{$tasks['employee']['id']}/upload", ['file' => $pdf()])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->apiAs($emp, 'POST', "/api/v1/onboarding/tasks/{$tasks['employee']['id']}/review", ['decision' => 'accept'])->assertForbidden();
        $this->apiAs($mgr, 'GET', "/api/v1/onboarding/tasks/{$tasks['employee']['id']}/document")->assertNotFound();
        $this->apiAs($this->hr, 'GET', "/api/v1/onboarding/tasks/{$tasks['employee']['id']}/document")->assertOk();
        $this->apiAs($this->hr, 'POST', "/api/v1/onboarding/tasks/{$tasks['employee']['id']}/review", ['decision' => 'return'])->assertUnprocessable();
        $this->apiAs($this->hr, 'POST', "/api/v1/onboarding/tasks/{$tasks['employee']['id']}/review", ['decision' => 'return', 'note' => 'Page 2 missing'])
            ->assertOk()->assertJsonPath('data.status', 'returned');
        $this->apiAs($emp, 'POST', "/api/v1/onboarding/tasks/{$tasks['employee']['id']}/upload", ['file' => $pdf()])->assertOk();
        $this->assertCount(1, Storage::disk(config('security.files.disk'))->allFiles());
        $this->apiAs($this->hr, 'POST', "/api/v1/onboarding/tasks/{$tasks['employee']['id']}/review", ['decision' => 'accept'])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->apiAs($mgr, 'POST', "/api/v1/onboarding/tasks/{$tasks['manager']['id']}/complete")->assertOk();
        $this->apiAs($mgr, 'POST', "/api/v1/onboarding/tasks/{$tasks['manager']['id']}/complete")->assertStatus(409);
        $this->apiAs($mgr, 'POST', "/api/v1/onboarding/tasks/{$tasks['hr']['id']}/complete")->assertForbidden();
        $this->apiAs($this->hr, 'POST', "/api/v1/onboarding/tasks/{$tasks['hr']['id']}/complete")->assertOk();

        $done = $this->apiAs($emp, 'GET', "/api/v1/onboarding/plans/{$plan['id']}")->assertOk()->json('data');
        $this->assertSame('completed', $done['status']);
        $this->assertSame(100, $done['progress']['percent']);
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('event', 'onboarding.document_reviewed')->count());
    }

    public function test_plans_are_tenant_and_person_scoped(): void
    {
        ['plan' => $plan] = $this->started('2027-03-01');
        [$stranger] = $this->person('employee');
        $other = $this->makeTenant();
        $otherHr = $this->makeUser($other, 'hr-administrator');
        $this->apiAs($stranger, 'GET', "/api/v1/onboarding/plans/{$plan['id']}")->assertNotFound();
        $this->assertSame([], $this->apiAs($stranger, 'GET', '/api/v1/onboarding/plans')->json('data'));
        $this->apiAs($otherHr, 'GET', "/api/v1/onboarding/plans/{$plan['id']}")->assertNotFound();
        $this->apiAs($otherHr, 'POST', '/api/v1/onboarding/tasks/'.$plan['tasks'][2]['id'].'/complete')->assertNotFound();
        $this->assertSame([], $this->apiAs($otherHr, 'GET', '/api/v1/onboarding/plans')->json('data'));
    }

    public function test_reminders_go_to_the_responsible_person_once_per_day(): void
    {
        Notification::fake();
        $this->travelTo(now()->setDate(2027, 2, 26)->setTime(9, 0));
        ['employee' => $emp, 'manager' => $mgr] = $this->started('2027-03-01');

        $this->artisan('wingora:onboarding:reminders')->assertSuccessful();
        $this->artisan('wingora:onboarding:reminders')->assertSuccessful();
        // Due 27 Feb (employee) is within two days; 1 Mar (manager) is not yet; 6 Mar (HR) is not.
        Notification::assertSentToTimes($emp, OnboardingTaskReminder::class, 1);
        Notification::assertNotSentTo($mgr, OnboardingTaskReminder::class);

        $this->travel(1)->days();
        $this->artisan('wingora:onboarding:reminders')->assertSuccessful();
        Notification::assertSentToTimes($emp, OnboardingTaskReminder::class, 2);
        Notification::assertSentToTimes($mgr, OnboardingTaskReminder::class, 1);
        Notification::assertNotSentTo($this->hr, OnboardingTaskReminder::class);
    }
}
