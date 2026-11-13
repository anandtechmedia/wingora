<?php

namespace Tests\Feature\Recruitment;

use App\Ai\Completion;
use App\Ai\LanguageModel;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\LegalEntity;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Country\CountryTestCase;

class RecruitmentTest extends CountryTestCase
{
    private Tenant $tenant;

    private LegalEntity $us;

    private User $hr;

    private User $hr2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('security.files.disk'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->tenant = $this->makeTenant();
        $this->us = $this->entity($this->tenant, 'US');
        $this->hr = $this->makeUser($this->tenant, 'hr-administrator');
        $this->hr2 = $this->makeUser($this->tenant, 'hr-administrator');
        $this->apiAs($this->hr, 'PATCH', '/api/v1/recruitment/settings', ['careers_enabled' => true, 'careers_slug' => 'acme-jobs', 'retention_days' => 90])->assertOk();
    }

    private function opening(array $extra = [], ?User $as = null, bool $publish = true): array
    {
        $o = $this->apiAs($as ?? $this->hr, 'POST', '/api/v1/recruitment/openings', $extra + ['title' => 'Backend Engineer', 'legal_entity_id' => $this->us->public_id,
            'description' => 'Build and run the Wingora platform APIs.', 'requirements' => 'PHP and MySQL'])->assertCreated()->json('data');
        if ($publish) {
            $this->apiAs($as ?? $this->hr, 'POST', "/api/v1/recruitment/openings/{$o['id']}/publish")->assertOk();
        }

        return $o;
    }

    private function cv(string $name = 'cv.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nCandidate CV\n%%EOF");
    }

    private function apply(string $slug, array $extra = [], string $site = 'acme-jobs')
    {
        return $this->post("/api/v1/public/careers/{$site}/jobs/{$slug}/apply", $extra + ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@example.com',
            'cover_letter' => 'I am Ana Ruiz, reach me at ana@example.com.', 'resume' => $this->cv(), 'consent' => '1', 'consent_version' => 'v1'], ['Accept' => 'application/json']);
    }

    /** @return array<string, mixed> the single application on the board */
    private function card(): array
    {
        return collect($this->apiAs($this->hr, 'GET', '/api/v1/recruitment/board')->assertOk()->json('data'))->flatMap(fn ($s) => $s['cards'])->first();
    }

    private function audits(string $event): int
    {
        return AuditLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('event', $event)->count();
    }

    public function test_public_careers_show_published_openings_only_and_require_consent(): void
    {
        $draft = $this->opening(['title' => 'Draft role'], publish: false);
        $live = $this->opening();
        $site = $this->getJson('/api/v1/public/careers/acme-jobs')->assertOk();
        $this->assertSame([$live['slug']], array_column($site->json('data.openings'), 'slug'));
        $this->assertArrayNotHasKey('hiring_manager', $site->json('data.openings.0'));
        $this->getJson("/api/v1/public/careers/acme-jobs/jobs/{$draft['slug']}")->assertNotFound();

        $this->apply($live['slug'], ['consent' => '0'])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->apply($live['slug'], ['website' => 'http://spam'])->assertUnprocessable();
        $this->apply($live['slug'], ['consent_version' => 'v0'])->assertStatus(409);
        $this->apply($live['slug'], ['resume' => UploadedFile::fake()->createWithContent('cv.txt', 'plain text, not a CV document')])->assertUnprocessable();
        $this->apply($live['slug'])->assertStatus(202)->assertExactJson(['data' => ['status' => 'received']]);
        // A repeat application answers the same way and creates nothing new.
        $this->apply($live['slug'])->assertStatus(202)->assertExactJson(['data' => ['status' => 'received']]);

        $this->assertSame(1, JobApplication::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $card = $this->card();
        $this->assertSame('applied', $card['stage']['key']);
        $this->assertSame('v1', $card['consent']['version']);
        $this->assertSame(1, $this->audits('recruitment.application_received'));
        $raw = Candidate::withoutGlobalScopes()->first()->getRawOriginal('email');
        $this->assertStringNotContainsString('ana@example.com', $raw);

        $this->apiAs($this->hr, 'PATCH', '/api/v1/recruitment/settings', ['careers_enabled' => false])->assertOk();
        $this->getJson('/api/v1/public/careers/acme-jobs')->assertNotFound();
    }

    public function test_careers_site_never_reaches_another_tenant(): void
    {
        $other = $this->makeTenant();
        $otherEntity = $this->entity($other, 'CA');
        $otherHr = $this->makeUser($other, 'hr-administrator');
        $o = $this->apiAs($otherHr, 'POST', '/api/v1/recruitment/openings', ['title' => 'Other', 'legal_entity_id' => $otherEntity->public_id,
            'description' => 'A role at another organization entirely.'])->assertCreated()->json('data');
        $this->apiAs($otherHr, 'POST', "/api/v1/recruitment/openings/{$o['id']}/publish")->assertOk();
        $this->apiAs($otherHr, 'PATCH', '/api/v1/recruitment/settings', ['careers_slug' => 'acme-jobs'])->assertUnprocessable();

        $this->getJson("/api/v1/public/careers/acme-jobs/jobs/{$o['slug']}")->assertNotFound();
        $this->apply($o['slug'])->assertNotFound();
        $this->getJson('/api/v1/public/careers/nobody-here')->assertNotFound();

        $mine = $this->opening();
        $this->apply($mine['slug'])->assertStatus(202);
        $id = $this->card()['id'];
        $this->apiAs($otherHr, 'GET', "/api/v1/recruitment/applications/{$id}")->assertNotFound();
        $this->apiAs($otherHr, 'GET', "/api/v1/recruitment/openings/{$mine['id']}")->assertNotFound();
        $this->assertSame([], collect($this->apiAs($otherHr, 'GET', '/api/v1/recruitment/board')->json('data'))->flatMap(fn ($s) => $s['cards'])->all());
    }

    public function test_candidate_documents_are_private_and_every_view_is_audited(): void
    {
        $o = $this->opening();
        $this->apply($o['slug'])->assertStatus(202);
        $app = $this->apiAs($this->hr, 'GET', '/api/v1/recruitment/applications/'.$this->card()['id'])->assertOk()->json('data');
        $doc = $app['documents'][0];
        $this->assertSame('resume', $doc['kind']);

        $this->apiAs($this->hr, 'GET', "/api/v1/recruitment/documents/{$doc['id']}/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(1, $this->audits('candidate.document_viewed'));

        $employee = $this->makeUser($this->tenant, 'employee');
        $auditor = $this->makeUser($this->tenant, 'auditor');
        foreach ([$employee, $auditor] as $u) {
            $this->apiAs($u, 'GET', "/api/v1/recruitment/documents/{$doc['id']}/download")->assertNotFound();
            $this->apiAs($u, 'GET', "/api/v1/recruitment/applications/{$app['id']}")->assertNotFound();
        }
        // The generic file endpoint never serves recruitment files, even to HR.
        $file = StoredFile::withoutGlobalScopes()->where('category', 'recruitment')->firstOrFail();
        $this->apiAs($this->hr, 'GET', "/api/v1/files/{$file->public_id}/download")->assertForbidden();
        $this->apiAs($this->hr, 'POST', '/api/v1/files', ['file' => $this->cv(), 'category' => 'recruitment'])->assertForbidden();
    }

    public function test_kanban_moves_use_versions_so_concurrent_moves_cannot_overwrite(): void
    {
        $o = $this->opening();
        $this->apply($o['slug'])->assertStatus(202);
        $card = $this->card();
        $stages = collect($this->apiAs($this->hr, 'GET', '/api/v1/recruitment/settings')->json('data.stages'))->keyBy('key');

        $moved = $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/move", ['stage_id' => $stages['screening']['id'], 'version' => $card['version']])
            ->assertOk()->json('data');
        $this->assertSame('screening', $moved['stage']['key']);
        $this->apiAs($this->hr2, 'POST', "/api/v1/recruitment/applications/{$card['id']}/move", ['stage_id' => $stages['interview']['id'], 'version' => $card['version']])
            ->assertStatus(409);
        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/move", ['stage_id' => $stages['hired']['id'], 'version' => $moved['version']])
            ->assertUnprocessable();
        $this->assertSame(1, $this->audits('recruitment.application_moved'));

        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/reject", ['reason' => 'Role filled', 'version' => $moved['version']])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.stage.key', 'rejected');
        $this->assertNotNull(JobApplication::withoutGlobalScopes()->first()->retain_until);
    }

    public function test_department_managers_see_only_their_openings_and_interviewers_only_their_interviews(): void
    {
        [$mgrUser, $mgr] = $this->person('department-manager');
        $dept = Tenancy::run($this->tenant, fn () => Department::query()->create(['name' => 'Engineering']));
        $dept->forceFill(['manager_employee_id' => $mgr->id])->save();
        $mine = $this->opening(['department_id' => $dept->public_id]);
        $notMine = $this->opening(['title' => 'Sales lead']);
        $this->apply($mine['slug'])->assertStatus(202);
        $this->apply($notMine['slug'], ['email' => 'bo@example.com', 'first_name' => 'Bo'])->assertStatus(202);

        $openings = $this->apiAs($mgrUser, 'GET', '/api/v1/recruitment/openings')->assertOk()->json('data');
        $this->assertSame([$mine['id']], array_column($openings, 'id'));
        $cards = collect($this->apiAs($mgrUser, 'GET', '/api/v1/recruitment/board')->json('data'))->flatMap(fn ($s) => $s['cards']);
        $this->assertSame(['Backend Engineer'], $cards->pluck('opening.title')->all());
        $this->apiAs($mgrUser, 'POST', '/api/v1/recruitment/openings', ['title' => 'X', 'legal_entity_id' => $this->us->public_id, 'description' => 'Not allowed to create.'])
            ->assertForbidden();

        [$panelUser] = $this->person('employee');
        [$otherUser] = $this->person('employee');
        $card = $cards->first();
        $start = now('America/New_York')->addDay()->setTime(10, 0);
        $interview = $this->apiAs($mgrUser, 'POST', '/api/v1/recruitment/interviews', ['application_id' => $card['id'], 'title' => 'Technical', 'starts_at' => $start->format('Y-m-d\TH:i'),
            'duration_minutes' => 60, 'timezone' => 'America/New_York', 'mode' => 'video', 'panel' => [$panelUser->public_id, $mgrUser->public_id]])->assertCreated()->json('data');
        $this->assertSame($start->utc()->toIso8601String(), $interview['starts_at']);

        $this->apiAs($panelUser, 'GET', "/api/v1/recruitment/applications/{$card['id']}")->assertOk();
        $this->apiAs($otherUser, 'GET', "/api/v1/recruitment/applications/{$card['id']}")->assertNotFound();
        $this->assertCount(1, $this->apiAs($panelUser, 'GET', '/api/v1/recruitment/interviews')->json('data'));
        $this->assertCount(0, $this->apiAs($otherUser, 'GET', '/api/v1/recruitment/interviews')->json('data'));
        $this->apiAs($panelUser, 'POST', "/api/v1/recruitment/applications/{$card['id']}/move", ['stage_id' => $card['stage']['id'], 'version' => 1])->assertForbidden();

        $feedback = ['rating' => 4, 'recommendation' => 'yes', 'strengths' => 'Clear thinking'];
        $this->apiAs($panelUser, 'POST', "/api/v1/recruitment/interviews/{$interview['id']}/feedback", $feedback)->assertStatus(409);
        $this->travel(2)->days();
        $this->apiAs($otherUser, 'POST', "/api/v1/recruitment/interviews/{$interview['id']}/feedback", $feedback)->assertForbidden();
        $this->apiAs($panelUser, 'POST', "/api/v1/recruitment/interviews/{$interview['id']}/feedback", $feedback)->assertCreated();
        $this->apiAs($panelUser, 'POST', "/api/v1/recruitment/interviews/{$interview['id']}/feedback", $feedback)->assertStatus(409);
        // The manager has not submitted yet, so colleagues' feedback stays hidden from them.
        $seen = $this->apiAs($mgrUser, 'GET', '/api/v1/recruitment/interviews')->json('data.0');
        $this->assertNull($seen['feedback']);
        $this->assertTrue(collect($seen['panel'])->firstWhere('id', $panelUser->public_id)['submitted']);
        $this->apiAs($mgrUser, 'POST', "/api/v1/recruitment/interviews/{$interview['id']}/feedback", ['rating' => 3, 'recommendation' => 'no'])->assertCreated();
        $this->assertCount(2, $this->apiAs($mgrUser, 'GET', '/api/v1/recruitment/interviews')->json('data.0.feedback'));
        $this->assertSame(2, $this->audits('recruitment.feedback_submitted'));
    }

    public function test_offers_need_a_second_approver_and_hiring_is_a_separate_human_action(): void
    {
        $o = $this->opening();
        $this->apply($o['slug'])->assertStatus(202);
        $card = $this->card();
        $hrManager = $this->makeUser($this->tenant, 'hr-manager');
        $this->apiAs($hrManager, 'POST', "/api/v1/recruitment/applications/{$card['id']}/offers", ['job_title' => 'Engineer', 'base_salary' => '95000.00', 'pay_frequency' => 'annual'])
            ->assertForbidden();
        $offer = $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/offers", ['job_title' => 'Engineer', 'base_salary' => '95000.00',
            'pay_frequency' => 'annual', 'start_date' => now()->addDays(14)->toDateString()])->assertCreated()->json('data');
        $this->assertSame(['amount' => '95000.00', 'currency' => 'USD'], $offer['base_salary']);
        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/offers", ['job_title' => 'Again', 'base_salary' => '1.00', 'pay_frequency' => 'annual'])
            ->assertStatus(409);

        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/offers/{$offer['id']}/approve")->assertForbidden();
        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/offers/{$offer['id']}/accept")->assertStatus(409);
        $hire = ['employee_number' => 'E-9001', 'version' => $card['version']];
        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/hire", $hire)->assertStatus(409);
        $this->apiAs($this->hr2, 'POST', "/api/v1/recruitment/offers/{$offer['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/offers/{$offer['id']}/send")->assertOk();
        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/offers/{$offer['id']}/accept")->assertOk()->assertJsonPath('data.next', []);

        $template = $this->apiAs($this->hr, 'POST', '/api/v1/onboarding/templates', ['name' => 'US hire', 'legal_entity_id' => $this->us->public_id])->assertCreated()->json('data');
        $this->apiAs($this->hr, 'POST', "/api/v1/onboarding/templates/{$template['id']}/tasks", ['stage' => 'first_day', 'title' => 'Laptop', 'assignee' => 'hr', 'due_offset_days' => 0])
            ->assertCreated();
        $hired = $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/hire", $hire + ['template_id' => $template['id']])->assertCreated()->json('data');
        $this->assertSame('hired', $hired['application']['status']);
        $this->assertSame('E-9001', $hired['employee']['employee_number']);
        $this->assertNotNull($hired['onboarding_plan_id']);
        $plan = $this->apiAs($this->hr, 'GET', "/api/v1/onboarding/plans/{$hired['onboarding_plan_id']}")->assertOk()->json('data');
        $this->assertSame(now()->addDays(14)->toDateString(), $plan['tasks'][0]['due_on']);
        $this->assertSame(1, $this->audits('recruitment.candidate_hired'));
        $this->assertSame(4, $this->audits('recruitment.offer_changed'));
        $log = AuditLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('event', 'recruitment.offer_changed')->get();
        $this->assertStringNotContainsString('9500000', $log->toJson());
    }

    public function test_ai_drafts_are_advisory_and_never_change_the_application(): void
    {
        $o = $this->opening();
        $this->apply($o['slug'])->assertStatus(202);
        $card = $this->card();
        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/ai/summary")->assertStatus(409);
        $this->apiAs($this->hr, 'PATCH', '/api/v1/recruitment/settings', ['ai_assist_enabled' => true])->assertOk();
        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/ai/summary")->assertStatus(503);

        $fake = new class implements LanguageModel
        {
            public array $prompts = [];

            public function available(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'fake-model';
            }

            public function complete(string $system, string $prompt, int $maxTokens = 600): Completion
            {
                $this->prompts[] = $system."\n".$prompt;

                return new Completion('Summary: backend experience; CV supplied.', 100, 20);
            }
        };
        $this->app->instance(LanguageModel::class, $fake);
        $draft = $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/ai/summary")->assertCreated()->json('data');
        $this->assertSame('pending_review', $draft['status']);
        $this->assertTrue($draft['ai_generated']);
        $this->assertStringNotContainsString('ana@example.com', $fake->prompts[0]);
        $this->assertStringNotContainsString('Ruiz', $fake->prompts[0]);
        $this->assertStringContainsString('Do not score, rank, recommend', $fake->prompts[0]);

        $after = $this->apiAs($this->hr, 'GET', "/api/v1/recruitment/applications/{$card['id']}")->json('data');
        $this->assertSame([$card['status'], $card['stage']['key'], $card['version']], [$after['status'], $after['stage']['key'], $after['version']]);

        CandidateDocument::withoutGlobalScopes()->first()->forceFill(['kind' => 'other'])->saveQuietly();
        $suggested = $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$card['id']}/ai/organize-documents")->assertOk()->json('data');
        $this->assertSame('resume', $suggested[0]['suggested_kind']);
        $this->assertSame('other', CandidateDocument::withoutGlobalScopes()->first()->kind);

        $this->apiAs($this->hr2, 'POST', "/api/v1/recruitment/ai-drafts/{$draft['id']}/review", ['decision' => 'accept'])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame(1, $this->audits('ai.draft_generated'));
        $this->assertSame(1, $this->audits('ai.draft_reviewed'));
    }

    public function test_retention_purge_anonymizes_expired_candidates_but_keeps_held_ones(): void
    {
        $o = $this->opening();
        $this->apply($o['slug'])->assertStatus(202);
        $this->apply($o['slug'], ['email' => 'kept@example.com', 'first_name' => 'Kim'])->assertStatus(202);
        $kept = collect($this->apiAs($this->hr, 'GET', '/api/v1/recruitment/board')->json('data'))->flatMap(fn ($s) => $s['cards'])->firstWhere('candidate.first_name', 'Kim');
        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/applications/{$kept['id']}/hold", ['on_hold' => true])->assertOk();
        $this->apiAs($this->hr, 'POST', "/api/v1/recruitment/openings/{$o['id']}/close")->assertOk();
        $this->assertSame(2, Storage::disk(config('security.files.disk'))->allFiles() ? count(Storage::disk(config('security.files.disk'))->allFiles()) : 0);

        $this->artisan('wingora:recruitment:purge')->assertSuccessful();
        $this->assertSame(0, Candidate::withoutGlobalScopes()->whereNotNull('anonymized_at')->count());

        $this->travel(91)->days();
        $this->artisan('wingora:recruitment:purge')->assertSuccessful();
        $candidates = Candidate::withoutGlobalScopes()->get()->keyBy('first_name');
        $this->assertNotNull($candidates['Erased']->anonymized_at);
        $this->assertNull($candidates['Kim']->anonymized_at);
        $this->assertCount(1, Storage::disk(config('security.files.disk'))->allFiles());
        $this->assertSame(1, $this->audits('candidate.retention_purged'));
    }

    public function test_hr_can_erase_a_candidate_on_request(): void
    {
        $o = $this->opening();
        $this->apply($o['slug'])->assertStatus(202);
        $card = $this->card();
        $this->apiAs($this->makeUser($this->tenant, 'hr-manager'), 'POST', "/api/v1/recruitment/applications/{$card['id']}/erase", ['reason' => 'Asked'])->assertOk();
        $this->assertSame('Erased candidate', $this->apiAs($this->hr, 'GET', "/api/v1/recruitment/applications/{$card['id']}")->json('data.candidate.name'));
        $this->assertSame('withdrawn', JobApplication::withoutGlobalScopes()->first()->status);
        $this->assertSame(0, CandidateDocument::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk(config('security.files.disk'))->allFiles());
        $this->assertSame(1, $this->audits('candidate.erased'));
    }

    /** @return array{0: User, 1: Employee} */
    private function person(string $role): array
    {
        $user = $this->makeUser($this->tenant, $role);
        $employee = $this->makeEmployee($this->tenant, null, $user);
        $employee->forceFill(['legal_entity_id' => $this->us->id])->saveQuietly();

        return [$user->fresh(), $employee->fresh()];
    }
}
