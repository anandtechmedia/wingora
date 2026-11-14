<?php

namespace Tests\Feature\Performance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class PerformanceTest extends SecurityTestCase
{
    private Tenant $tenant;

    private User $admin;

    private User $manager;

    private User $employee;

    private User $peer;

    private User $outsider;

    private Employee $employeeRecord;

    private Employee $managerRecord;

    private Employee $peerRecord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeUser($this->tenant, 'hr-administrator');
        $this->manager = $this->makeUser($this->tenant, 'department-manager');
        $this->employee = $this->makeUser($this->tenant, 'employee');
        $this->peer = $this->makeUser($this->tenant, 'employee');
        $this->outsider = $this->makeUser($this->tenant, 'employee');
        $this->makeEmployee($this->tenant, null, $this->admin);
        $this->managerRecord = $managerRecord = $this->makeEmployee($this->tenant, null, $this->manager);
        $this->employeeRecord = $this->makeEmployee($this->tenant, null, $this->employee);
        $this->peerRecord = $peerRecord = $this->makeEmployee($this->tenant, null, $this->peer);
        $this->makeEmployee($this->tenant, null, $this->outsider);
        DB::table('employees')->whereIn('id', [$this->employeeRecord->id, $peerRecord->id])->update(['manager_id' => $managerRecord->id]);
    }

    private function cycle(): array
    {
        $cycle = $this->apiAs($this->admin, 'POST', '/api/v1/performance/cycles', [
            'name' => 'H1 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-06-30', 'rating_scale' => 5,
            'questions' => [['key' => 'wins', 'label' => 'Biggest wins', 'type' => 'text'], ['key' => 'growth', 'label' => 'Growth', 'type' => 'rating']],
        ])->assertCreated()->json('data');

        return $this->apiAs($this->admin, 'POST', "/api/v1/performance/cycles/{$cycle['id']}/launch", ['version' => $cycle['version']])->assertOk()->json('data');
    }

    private function reviewOf(User $as, User $subject): array
    {
        $id = [$this->employee->id => $this->employeeRecord, $this->peer->id => $this->peerRecord][$subject->id]->public_id;
        $rows = collect($this->apiAs($as, 'GET', '/api/v1/performance/reviews?scope=all')->assertOk()->json('data'));

        return $rows->firstWhere('employee.id', $id);
    }

    public function test_okr_check_ins_drive_progress_and_visibility_follows_reporting_lines(): void
    {
        $okr = $this->apiAs($this->employee, 'POST', '/api/v1/performance/objectives', [
            'kind' => 'okr', 'title' => 'Ship the mobile app', 'due_on' => '2027-03-31',
            'key_results' => [['title' => 'Screens done', 'target_value' => 10, 'weight' => 1], ['title' => 'Crash-free %', 'start_value' => 90, 'target_value' => 100, 'weight' => 1]],
        ])->assertCreated()->json('data');
        $this->assertSame(0, $okr['progress']);
        $this->assertSame($this->employeeRecord->public_id, $okr['employee']['id']);

        $after = $this->apiAs($this->employee, 'POST', "/api/v1/performance/objectives/{$okr['id']}/check-ins", ['key_result_id' => $okr['key_results'][0]['id'], 'value' => 5, 'note' => 'Halfway'])
            ->assertCreated()->json('data');
        $this->assertSame(25, $after['progress']);
        $this->assertSame('Halfway', $after['check_ins'][0]['note']);

        // An OKR needs key results, and a check-in on one must name a key result.
        $this->apiAs($this->employee, 'POST', '/api/v1/performance/objectives', ['kind' => 'okr', 'title' => 'Empty'])->assertStatus(422)->assertJsonValidationErrors('key_results');
        $this->apiAs($this->employee, 'POST', "/api/v1/performance/objectives/{$okr['id']}/check-ins", ['progress' => 80])->assertStatus(422);

        // The manager sees and edits the report's OKR; a colleague who is not their manager cannot see it.
        $this->apiAs($this->manager, 'GET', "/api/v1/performance/objectives/{$okr['id']}")->assertOk()->assertJsonPath('data.can_edit', true);
        $this->apiAs($this->manager, 'PATCH', "/api/v1/performance/objectives/{$okr['id']}", ['version' => $after['version'], 'title' => 'Ship mobile v1'])->assertOk();
        $this->apiAs($this->outsider, 'GET', "/api/v1/performance/objectives/{$okr['id']}")->assertNotFound();
        $this->apiAs($this->outsider, 'PATCH', "/api/v1/performance/objectives/{$okr['id']}", ['version' => 3, 'title' => 'x'])->assertNotFound();
        $this->assertCount(0, $this->apiAs($this->outsider, 'GET', '/api/v1/performance/objectives')->json('data'));
        // Stale versions are refused.
        $this->apiAs($this->employee, 'PATCH', "/api/v1/performance/objectives/{$okr['id']}", ['version' => $after['version'], 'title' => 'Late'])->assertStatus(409);
        // Employees cannot create objectives for others.
        $this->apiAs($this->employee, 'POST', '/api/v1/performance/objectives', ['kind' => 'objective', 'title' => 'x', 'employee_id' => $this->peerRecord->public_id])->assertNotFound();

        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', 'performance.objective_created')->exists());
    }

    public function test_objectives_align_only_to_visible_objectives_without_loops(): void
    {
        $company = $this->apiAs($this->admin, 'POST', '/api/v1/performance/objectives', ['kind' => 'objective', 'title' => 'Company goal'])->assertCreated()->json('data');
        $child = $this->apiAs($this->admin, 'POST', '/api/v1/performance/objectives', ['kind' => 'objective', 'title' => 'Team goal', 'parent_id' => $company['id']])->assertCreated()->json('data');
        $this->assertSame('Company goal', $child['parent']['title']);
        $this->apiAs($this->admin, 'PATCH', "/api/v1/performance/objectives/{$company['id']}", ['version' => $company['version'], 'parent_id' => $child['id']])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
        // The employee cannot see the admin's objective, so cannot align to it.
        $this->apiAs($this->employee, 'POST', '/api/v1/performance/objectives', ['kind' => 'objective', 'title' => 'Mine', 'parent_id' => $company['id']])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_review_cycle_runs_self_then_manager_then_acknowledgement(): void
    {
        $this->apiAs($this->employee, 'POST', '/api/v1/performance/cycles', ['name' => 'x', 'starts_on' => '2027-01-01', 'ends_on' => '2027-02-01', 'questions' => [['key' => 'a', 'label' => 'A', 'type' => 'text']]])->assertForbidden();
        $cycle = $this->cycle();
        $this->assertSame('open', $cycle['status']);
        $this->assertSame(5, $cycle['review_count']);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', 'performance.review_cycle_launched')->exists());

        $review = $this->reviewOf($this->employee, $this->employee);
        $this->assertTrue($review['can_self']);
        $this->assertCount(1, $this->apiAs($this->employee, 'GET', '/api/v1/performance/reviews')->json('data'));
        // The manager cannot skip the self assessment, and answers are validated against the cycle's questions.
        $this->apiAs($this->manager, 'POST', "/api/v1/performance/reviews/{$review['id']}/manager", ['version' => 1, 'rating' => 4, 'answers' => ['wins' => 'Good', 'growth' => 4]])->assertStatus(422);
        $this->apiAs($this->employee, 'POST', "/api/v1/performance/reviews/{$review['id']}/self", ['version' => 1, 'answers' => ['wins' => 'Shipped', 'growth' => 9]])
            ->assertStatus(422)->assertJsonValidationErrors('answers.growth');
        $this->apiAs($this->peer, 'POST', "/api/v1/performance/reviews/{$review['id']}/self", ['version' => 1, 'answers' => ['wins' => 'x', 'growth' => 3]])->assertNotFound();
        $this->apiAs($this->employee, 'POST', "/api/v1/performance/reviews/{$review['id']}/self", ['version' => 1, 'answers' => ['wins' => 'Shipped', 'growth' => 4]])
            ->assertOk()->assertJsonPath('data.status', 'pending_manager');

        // An employee cannot write their own manager assessment.
        $this->apiAs($this->employee, 'POST', "/api/v1/performance/reviews/{$review['id']}/manager", ['version' => 2, 'rating' => 5, 'answers' => ['wins' => 'Me', 'growth' => 5]])->assertForbidden();
        $managerView = $this->apiAs($this->manager, 'GET', "/api/v1/performance/reviews/{$review['id']}")->assertOk()->json('data');
        $this->assertSame('Shipped', $managerView['self_answers']['wins']);
        $this->apiAs($this->manager, 'POST', "/api/v1/performance/reviews/{$review['id']}/manager", ['version' => 2, 'rating' => 4, 'answers' => ['wins' => 'Strong delivery', 'growth' => 4]])
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $mine = $this->apiAs($this->employee, 'GET', "/api/v1/performance/reviews/{$review['id']}")->assertOk()->json('data');
        $this->assertSame(4, $mine['rating']);
        $this->assertSame('Strong delivery', $mine['manager_answers']['wins']);
        $this->apiAs($this->employee, 'POST', "/api/v1/performance/reviews/{$review['id']}/acknowledge", ['version' => 3])->assertOk()->assertJsonPath('data.status', 'acknowledged');

        // Review answers are encrypted at rest and never copied into the audit trail.
        $this->assertStringNotContainsString('Strong delivery', (string) DB::table('performance_reviews')->where('public_id', $review['id'])->value('manager_answers'));
        $this->assertFalse(AuditLog::query()->withoutGlobalScopes()->where('new_values', 'like', '%Strong delivery%')->exists());
    }

    public function test_manager_assessment_stays_hidden_from_the_employee_until_completed(): void
    {
        $this->cycle();
        $review = $this->reviewOf($this->employee, $this->employee);
        $this->apiAs($this->employee, 'POST', "/api/v1/performance/reviews/{$review['id']}/self", ['version' => 1, 'answers' => ['wins' => 'Shipped', 'growth' => 4]])->assertOk();
        $this->apiAs($this->manager, 'POST', "/api/v1/performance/reviews/{$review['id']}/feedback", ['reviewer_id' => $this->peerRecord->public_id, 'relationship' => 'peer'])->assertCreated();
        $request = $this->apiAs($this->peer, 'GET', '/api/v1/performance/feedback')->assertOk()->json('data.0');
        $this->apiAs($this->peer, 'POST', "/api/v1/performance/feedback/{$request['id']}/submit", ['strengths' => 'Calm under pressure', 'improvements' => 'Delegate more', 'rating' => 4])->assertOk();

        $mine = $this->apiAs($this->employee, 'GET', "/api/v1/performance/reviews/{$review['id']}")->assertOk()->json('data');
        $this->assertNull($mine['rating']);
        $this->assertNull($mine['manager_answers']);
        $this->assertSame([], $mine['feedback']);
    }

    public function test_360_feedback_is_answered_only_by_the_reviewer_and_anonymous_to_everyone(): void
    {
        $this->cycle();
        $review = $this->reviewOf($this->manager, $this->employee);
        $peerId = $this->peerRecord->public_id;
        $this->apiAs($this->employee, 'POST', "/api/v1/performance/reviews/{$review['id']}/feedback", ['reviewer_id' => $peerId, 'relationship' => 'peer'])->assertForbidden();
        $this->apiAs($this->manager, 'POST', "/api/v1/performance/reviews/{$review['id']}/feedback", ['reviewer_id' => $this->employeeRecord->public_id, 'relationship' => 'peer'])
            ->assertStatus(422)->assertJsonValidationErrors('reviewer_id');
        $this->apiAs($this->manager, 'POST', "/api/v1/performance/reviews/{$review['id']}/feedback", ['reviewer_id' => $peerId, 'relationship' => 'peer'])->assertCreated();
        $this->apiAs($this->manager, 'POST', "/api/v1/performance/reviews/{$review['id']}/feedback", ['reviewer_id' => $peerId, 'relationship' => 'peer'])->assertStatus(422);
        $this->assertNotEmpty($this->apiAs($this->manager, 'GET', '/api/v1/performance/people?q='.urlencode($this->peerRecord->first_name))->assertOk()->json('data'));
        $this->apiAs($this->employee, 'GET', '/api/v1/performance/people')->assertForbidden();

        $request = $this->apiAs($this->peer, 'GET', '/api/v1/performance/feedback')->assertOk()->json('data.0');
        $this->assertSame($this->employeeRecord->public_id, $request['subject']['id']);
        $this->assertCount(0, $this->apiAs($this->outsider, 'GET', '/api/v1/performance/feedback')->json('data'));
        $this->apiAs($this->outsider, 'POST', "/api/v1/performance/feedback/{$request['id']}/submit", ['strengths' => 'x', 'improvements' => 'y'])->assertNotFound();
        $this->apiAs($this->peer, 'POST', "/api/v1/performance/feedback/{$request['id']}/submit", ['strengths' => 'Calm under pressure', 'improvements' => 'Delegate more'])->assertOk();
        $this->apiAs($this->peer, 'POST', "/api/v1/performance/feedback/{$request['id']}/decline")->assertStatus(422);

        $managerView = $this->apiAs($this->manager, 'GET', "/api/v1/performance/reviews/{$review['id']}")->json('data.feedback.0');
        $this->assertNull($managerView['reviewer']);
        $this->assertSame('Calm under pressure', $managerView['answers']['strengths']);
    }

    public function test_one_to_one_private_notes_are_for_the_manager_only(): void
    {
        $meeting = $this->apiAs($this->employee, 'POST', '/api/v1/performance/meetings', ['employee_id' => $this->employeeRecord->public_id, 'scheduled_at' => '2027-02-01T15:00:00Z', 'agenda' => 'Career goals'])
            ->assertCreated()->json('data');
        $this->assertSame($this->managerRecord->public_id, $meeting['manager']['id']);
        $this->apiAs($this->employee, 'PATCH', "/api/v1/performance/meetings/{$meeting['id']}", ['version' => 1, 'private_notes' => 'mine'])->assertForbidden();
        $this->apiAs($this->manager, 'PATCH', "/api/v1/performance/meetings/{$meeting['id']}", ['version' => 1, 'shared_notes' => 'Agreed a plan', 'private_notes' => 'Watch workload',
            'action_items' => [['text' => 'Draft plan', 'owner' => 'employee']], 'status' => 'completed'])->assertOk()->assertJsonPath('data.private_notes', 'Watch workload');

        $mine = $this->apiAs($this->employee, 'GET', "/api/v1/performance/meetings/{$meeting['id']}")->assertOk()->json('data');
        $this->assertSame('Agreed a plan', $mine['shared_notes']);
        $this->assertNull($mine['private_notes']);
        $this->apiAs($this->admin, 'GET', "/api/v1/performance/meetings/{$meeting['id']}")->assertOk()->assertJsonPath('data.private_notes', null);
        $this->apiAs($this->outsider, 'GET', "/api/v1/performance/meetings/{$meeting['id']}")->assertNotFound();
        $this->apiAs($this->outsider, 'POST', '/api/v1/performance/meetings', ['employee_id' => $this->employeeRecord->public_id, 'scheduled_at' => '2027-02-01T15:00:00Z'])->assertNotFound();
        $this->assertStringNotContainsString('Watch workload', (string) DB::table('performance_meetings')->value('private_notes'));
    }

    public function test_history_is_limited_to_people_the_user_can_see(): void
    {
        $this->cycle();
        $review = $this->reviewOf($this->employee, $this->employee);
        $this->apiAs($this->employee, 'POST', "/api/v1/performance/reviews/{$review['id']}/self", ['version' => 1, 'answers' => ['wins' => 'a', 'growth' => 3]])->assertOk();
        $this->apiAs($this->manager, 'POST', "/api/v1/performance/reviews/{$review['id']}/manager", ['version' => 2, 'rating' => 3, 'answers' => ['wins' => 'b', 'growth' => 3]])->assertOk();

        $id = $this->employeeRecord->public_id;
        $this->apiAs($this->employee, 'GET', "/api/v1/performance/employees/{$id}/history")->assertOk()->assertJsonPath('data.reviews.0.rating', 3);
        $this->apiAs($this->manager, 'GET', "/api/v1/performance/employees/{$id}/history")->assertOk();
        $this->apiAs($this->admin, 'GET', "/api/v1/performance/employees/{$id}/history")->assertOk();
        $this->apiAs($this->outsider, 'GET', "/api/v1/performance/employees/{$id}/history")->assertNotFound();
    }

    public function test_tenant_isolation_and_plan_entitlement(): void
    {
        $okr = $this->apiAs($this->employee, 'POST', '/api/v1/performance/objectives', ['kind' => 'objective', 'title' => 'Private goal'])->assertCreated()->json('data');
        $other = $this->makeTenant();
        $otherAdmin = $this->makeUser($other, 'hr-administrator');
        $this->makeEmployee($other, null, $otherAdmin);
        $this->apiAs($otherAdmin, 'GET', "/api/v1/performance/objectives/{$okr['id']}")->assertNotFound();
        $this->assertCount(0, $this->apiAs($otherAdmin, 'GET', '/api/v1/performance/objectives')->json('data'));
        $this->apiAs($otherAdmin, 'GET', "/api/v1/performance/employees/{$this->employeeRecord->public_id}/history")->assertNotFound();

        Subscription::forTenant($this->tenant)->forceFill(['plan_id' => Plan::query()->where('code', 'starter')->value('id')])->saveQuietly();
        $this->apiAs($this->employee, 'GET', '/api/v1/performance/objectives')->assertStatus(402);
    }

    public function test_own_history_resolves_the_signed_in_employee(): void
    {
        $this->apiAs($this->employee, 'GET', '/api/v1/performance/history')
            ->assertOk()->assertJsonPath('data.employee.id', $this->employeeRecord->public_id);
    }

    public function test_edits_keep_check_in_history_and_validate_against_saved_values(): void
    {
        $okr = $this->apiAs($this->employee, 'POST', '/api/v1/performance/objectives', [
            'kind' => 'okr', 'title' => 'Grow usage', 'starts_on' => '2027-01-01', 'due_on' => '2027-03-31',
            'key_results' => [['title' => 'Signups', 'target_value' => 10], ['title' => 'Retention', 'target_value' => 10]],
        ])->assertCreated()->json('data');
        $okr = $this->apiAs($this->employee, 'POST', "/api/v1/performance/objectives/{$okr['id']}/check-ins", ['key_result_id' => $okr['key_results'][0]['id'], 'value' => 5])
            ->assertCreated()->json('data');

        $kept = $okr['key_results'][1];
        $okr = $this->apiAs($this->employee, 'PATCH', "/api/v1/performance/objectives/{$okr['id']}", [
            'version' => $okr['version'], 'key_results' => [['id' => $kept['id'], 'title' => $kept['title'], 'target_value' => 10]],
        ])->assertOk()->json('data');
        $this->assertCount(1, $okr['check_ins']);
        $this->assertNull($okr['check_ins'][0]['key_result']);

        $this->apiAs($this->employee, 'PATCH', "/api/v1/performance/objectives/{$okr['id']}", ['version' => $okr['version'], 'key_results' => []])
            ->assertStatus(422)->assertJsonValidationErrors('key_results');
        $this->apiAs($this->employee, 'PATCH', "/api/v1/performance/objectives/{$okr['id']}", ['version' => $okr['version'], 'starts_on' => '2027-06-01'])
            ->assertStatus(422)->assertJsonValidationErrors('due_on');

        $done = $this->apiAs($this->employee, 'POST', '/api/v1/performance/objectives', ['kind' => 'objective', 'title' => 'Certify', 'status' => 'completed', 'progress' => 100])
            ->assertCreated()->json('data');
        $this->assertSame(100, $done['progress']);
        $this->assertNotNull($done['completed_at']);

        $cycle = $this->apiAs($this->admin, 'POST', '/api/v1/performance/cycles', [
            'name' => 'H2 2027', 'starts_on' => '2027-07-01', 'ends_on' => '2027-12-31', 'questions' => [['key' => 'wins', 'label' => 'Wins', 'type' => 'text']],
        ])->assertCreated()->json('data');
        $this->apiAs($this->admin, 'PATCH', "/api/v1/performance/cycles/{$cycle['id']}", ['version' => $cycle['version'], 'starts_on' => '2028-01-15'])
            ->assertStatus(422)->assertJsonValidationErrors('ends_on');
    }
}
