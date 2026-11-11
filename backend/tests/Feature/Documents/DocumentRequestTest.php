<?php

namespace Tests\Feature\Documents;

use App\Audit\AuditCategory;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\SecurityTestCase;

class DocumentRequestTest extends SecurityTestCase
{
    private Tenant $tenant;

    private User $admin;

    private User $hr;

    private User $payroll;

    private User $manager;

    private User $employee;

    private Employee $employeeRecord;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('security.files.disk'));
        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeUser($this->tenant, 'hr-administrator');
        $this->hr = $this->makeUser($this->tenant, 'hr-manager');
        $this->payroll = $this->makeUser($this->tenant, 'payroll-administrator');
        $this->manager = $this->makeUser($this->tenant, 'department-manager');
        $this->employee = $this->makeUser($this->tenant, 'employee');
        $managerRecord = $this->makeEmployee($this->tenant, null, $this->manager);
        $this->employeeRecord = $this->makeEmployee($this->tenant, null, $this->employee);
        DB::table('employees')->where('id', $this->employeeRecord->id)->update(['manager_id' => $managerRecord->id, 'hire_date' => '2021-03-01']);
        foreach ([$this->admin, $this->hr, $this->payroll] as $u) {
            $this->makeEmployee($this->tenant, null, $u);
        }
    }

    private function template(array $attrs = []): array
    {
        return $this->apiAs($this->admin, 'POST', '/api/v1/document-templates', $attrs + [
            'name' => 'Employment verification', 'delivery' => 'generated',
            'body' => "To {{answers.addressee}},\nThis confirms that {{employee.name}} has worked as {{employee.job_title}} since {{employee.hire_date}}.",
            'fields' => [['key' => 'addressee', 'label' => 'Addressed to', 'type' => 'text', 'required' => true]],
        ])->assertCreated()->json('data');
    }

    private function submit(User $as, array $template, array $answers = ['addressee' => 'Consulate']): array
    {
        return $this->apiAs($as, 'POST', '/api/v1/document-requests', ['template_id' => $template['id'], 'answers' => $answers])->assertCreated()->json('data');
    }

    public function test_templates_reject_unknown_placeholders_and_unsafe_salary_letters(): void
    {
        $this->apiAs($this->admin, 'POST', '/api/v1/document-templates', ['name' => 'Bad', 'delivery' => 'generated', 'body' => 'Hi {{employee.salary}}'])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->apiAs($this->admin, 'POST', '/api/v1/document-templates', ['name' => 'Salary', 'delivery' => 'upload', 'includes_salary' => true])
            ->assertStatus(422)->assertJsonValidationErrors('includes_salary');
        $this->apiAs($this->admin, 'POST', '/api/v1/document-templates', ['name' => 'Salary', 'delivery' => 'generated', 'body' => 'No figure', 'includes_salary' => true])
            ->assertStatus(422)->assertJsonValidationErrors('includes_salary');
        $this->apiAs($this->hr, 'POST', '/api/v1/document-templates', ['name' => 'X', 'delivery' => 'upload'])->assertForbidden();
        $t = $this->template();
        // A partial update is validated against the merged template.
        $this->apiAs($this->admin, 'PATCH', "/api/v1/document-templates/{$t['id']}", ['fields' => []])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->assertSame([$t['id']], array_column($this->apiAs($this->employee, 'GET', '/api/v1/document-templates')->json('data'), 'id'));
        $this->apiAs($this->admin, 'PATCH', "/api/v1/document-templates/{$t['id']}", ['active' => false])->assertOk();
        $this->assertSame([], $this->apiAs($this->employee, 'GET', '/api/v1/document-templates')->json('data'));
        $this->apiAs($this->employee, 'POST', '/api/v1/document-requests', ['template_id' => $t['id'], 'answers' => ['addressee' => 'X']])->assertStatus(422);
    }

    public function test_manager_then_hr_approval_generated_delivery_and_private_download(): void
    {
        $t = $this->template();
        $this->apiAs($this->employee, 'POST', '/api/v1/document-requests', ['template_id' => $t['id'], 'answers' => []])->assertStatus(422)->assertJsonValidationErrors('answers.addressee');
        $this->apiAs($this->employee, 'POST', '/api/v1/document-requests', ['template_id' => $t['id'], 'answers' => ['addressee' => 'A', 'extra' => 'x']])->assertStatus(422);
        $r = $this->submit($this->employee, $t);
        $this->assertSame('pending_manager', $r['status']);
        $this->assertSame(1, $r['number']);
        $this->assertStringNotContainsString('Consulate', (string) DB::table('document_requests')->value('answers'), 'answers are encrypted at rest');

        // HR cannot skip ahead with a stale version; the requester cannot decide their own request.
        $this->apiAs($this->employee, 'POST', "/api/v1/document-requests/{$r['id']}/decide", ['decision' => 'approve', 'version' => 1])->assertForbidden();
        $other = $this->makeUser($this->tenant, 'department-manager');
        $this->makeEmployee($this->tenant, null, $other);
        $this->apiAs($other, 'GET', "/api/v1/document-requests/{$r['id']}")->assertNotFound();
        $this->assertSame([$r['id']], array_column($this->apiAs($this->manager, 'GET', '/api/v1/document-requests?scope=approvals')->json('data'), 'id'));
        $r = $this->apiAs($this->manager, 'POST', "/api/v1/document-requests/{$r['id']}/decide", ['decision' => 'approve', 'version' => 1])->assertOk()->json('data');
        $this->assertSame('pending_hr', $r['status']);
        $this->apiAs($this->manager, 'POST', "/api/v1/document-requests/{$r['id']}/decide", ['decision' => 'approve', 'version' => 2])->assertForbidden();
        $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$r['id']}/decide", ['decision' => 'approve', 'version' => 1])->assertStatus(409);
        $r = $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$r['id']}/decide", ['decision' => 'approve', 'version' => 2])->assertOk()->json('data');
        $this->assertSame('approved', $r['status']);
        $this->assertTrue($r['can']['deliver']);
        $this->apiAs($this->employee, 'POST', "/api/v1/document-requests/{$r['id']}/cancel", ['version' => 3])->assertStatus(409);
        $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$r['id']}/deliver", ['version' => 3, 'salary' => '1'])->assertStatus(422);
        $r = $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$r['id']}/deliver", ['version' => 3])->assertOk()->json('data');
        $this->assertSame('delivered', $r['status']);

        $mine = $this->apiAs($this->employee, 'GET', "/api/v1/document-requests/{$r['id']}")->assertOk()->json('data');
        $this->assertTrue($mine['can']['download']);
        $pdf = $this->apiAs($this->employee, 'GET', "/api/v1/document-requests/{$r['id']}/document")->assertOk()->streamedContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('Consulate', $pdf);
        $this->assertStringContainsString('2021-03-01', $pdf);
        $this->apiAs($this->manager, 'GET', "/api/v1/document-requests/{$r['id']}/document")->assertNotFound();
        $file = StoredFile::withoutGlobalScopes()->where('category', 'documents')->firstOrFail();
        foreach ([$this->employee, $this->hr, $this->admin] as $u) {
            $this->apiAs($u, 'GET', "/api/v1/files/{$file->public_id}/download")->assertForbidden();
            $this->apiAs($u, 'DELETE', "/api/v1/files/{$file->public_id}")->assertForbidden();
        }

        $events = AuditLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('event', 'like', 'documents.%')->pluck('event');
        foreach (['documents.request_submitted', 'documents.request_decided', 'documents.delivered', 'documents.downloaded'] as $e) {
            $this->assertContains($e, $events);
        }
        $this->assertSame(AuditCategory::Documents, AuditLog::withoutGlobalScopes()->where('event', 'documents.delivered')->first()->category);
        $this->assertStringNotContainsString('Consulate', AuditLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get(['old_values', 'new_values'])->toJson());
    }

    public function test_salary_letters_are_limited_to_payroll_and_the_figure_is_never_stored(): void
    {
        $t = $this->template(['name' => 'Salary certificate', 'requires_manager' => false, 'includes_salary' => true, 'fields' => [],
            'body' => '{{employee.name}} earns {{salary}} per year at {{entity.legal_name}}.']);
        $r = $this->submit($this->employee, $t, []);
        $this->assertSame('pending_hr', $r['status']);
        $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$r['id']}/decide", ['decision' => 'approve', 'version' => 1])->assertForbidden();
        $this->assertSame([], $this->apiAs($this->hr, 'GET', '/api/v1/document-requests?scope=approvals')->json('data'));
        $this->assertSame([$r['id']], array_column($this->apiAs($this->payroll, 'GET', '/api/v1/document-requests?scope=approvals')->json('data'), 'id'));
        $this->apiAs($this->payroll, 'POST', "/api/v1/document-requests/{$r['id']}/decide", ['decision' => 'approve', 'version' => 1])->assertOk();
        $this->apiAs($this->payroll, 'POST', "/api/v1/document-requests/{$r['id']}/deliver", ['version' => 2])->assertStatus(422)->assertJsonValidationErrors('salary');
        $this->apiAs($this->payroll, 'POST', "/api/v1/document-requests/{$r['id']}/deliver", ['version' => 2, 'salary' => 'USD 98,765.00'])->assertOk()->assertJsonPath('data.status', 'delivered');

        $this->assertStringContainsString('98,765.00', $this->apiAs($this->employee, 'GET', "/api/v1/document-requests/{$r['id']}/document")->assertOk()->streamedContent());
        $this->apiAs($this->hr, 'GET', "/api/v1/document-requests/{$r['id']}/document")->assertNotFound();
        $this->assertStringNotContainsString('98,765', DB::table('document_requests')->get()->toJson());

        // Turning the template into a non-salary one later must not open the delivered salary letter to HR.
        $this->apiAs($this->admin, 'PATCH', "/api/v1/document-templates/{$t['id']}", ['includes_salary' => false, 'body' => '{{employee.name}} works here.'])->assertOk();
        $this->apiAs($this->hr, 'GET', "/api/v1/document-requests/{$r['id']}/document")->assertNotFound();
        $this->apiAs($this->payroll, 'GET', "/api/v1/document-requests/{$r['id']}/document")->assertOk();
        $pending = $this->submit($this->manager, $t, []);
        $this->apiAs($this->admin, 'PATCH', "/api/v1/document-templates/{$t['id']}", ['includes_salary' => true, 'body' => '{{salary}}'])->assertOk();
        $this->apiAs($this->payroll, 'POST', "/api/v1/document-requests/{$pending['id']}/decide", ['decision' => 'approve', 'version' => 1])->assertNotFound();
        $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$pending['id']}/decide", ['decision' => 'approve', 'version' => 1])->assertOk();
        $this->assertStringNotContainsString('98,765', AuditLog::withoutGlobalScopes()->get(['old_values', 'new_values'])->toJson());
    }

    public function test_rejection_cancellation_self_approval_upload_delivery_and_tenant_isolation(): void
    {
        $t = $this->template(['requires_manager' => false]);
        $own = $this->submit($this->hr, $t);
        $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$own['id']}/decide", ['decision' => 'approve', 'version' => 1])->assertForbidden();
        $this->apiAs($this->admin, 'POST', "/api/v1/document-requests/{$own['id']}/decide", ['decision' => 'reject', 'version' => 1])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->apiAs($this->admin, 'POST', "/api/v1/document-requests/{$own['id']}/decide", ['decision' => 'reject', 'reason' => 'Use the self-service letter', 'version' => 1])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Use the self-service letter');

        $r = $this->submit($this->employee, $t);
        $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$r['id']}/cancel", ['version' => 1])->assertForbidden();
        $this->apiAs($this->employee, 'POST', "/api/v1/document-requests/{$r['id']}/cancel", ['version' => 1])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$r['id']}/decide", ['decision' => 'approve', 'version' => 2])->assertStatus(409);

        $upload = $this->apiAs($this->admin, 'POST', '/api/v1/document-templates', ['name' => 'Experience letter', 'delivery' => 'upload', 'requires_manager' => false])->assertCreated()->json('data');
        $u = $this->submit($this->employee, $upload, []);
        $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$u['id']}/decide", ['decision' => 'approve', 'version' => 1])->assertOk();
        $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$u['id']}/deliver", ['version' => 2])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->apiAs($this->hr, 'POST', "/api/v1/document-requests/{$u['id']}/deliver", ['version' => 2, 'file' => UploadedFile::fake()->createWithContent('letter.pdf', "%PDF-1.4\nsigned\n%%EOF")])
            ->assertOk()->assertJsonPath('data.document.name', 'letter.pdf');
        $this->assertSame("%PDF-1.4\nsigned\n%%EOF", $this->apiAs($this->employee, 'GET', "/api/v1/document-requests/{$u['id']}/document")->assertOk()->streamedContent());

        $colleague = $this->makeUser($this->tenant, 'employee');
        $this->makeEmployee($this->tenant, null, $colleague);
        $this->apiAs($colleague, 'GET', "/api/v1/document-requests/{$u['id']}")->assertNotFound();
        $this->apiAs($colleague, 'GET', "/api/v1/document-requests/{$u['id']}/document")->assertNotFound();
        $this->assertSame([], $this->apiAs($colleague, 'GET', '/api/v1/document-requests')->json('data'));
        $auditor = $this->makeUser($this->tenant, 'auditor');
        $this->apiAs($auditor, 'GET', '/api/v1/document-requests')->assertForbidden();
        $this->apiAs($auditor, 'GET', '/api/v1/document-templates')->assertForbidden();

        $other = $this->makeTenant();
        $foreign = $this->makeUser($other, 'hr-administrator');
        $this->makeEmployee($other, null, $foreign);
        $this->apiAs($foreign, 'GET', "/api/v1/document-requests/{$u['id']}")->assertNotFound();
        $this->apiAs($foreign, 'PATCH', "/api/v1/document-templates/{$t['id']}", ['name' => 'Hijack'])->assertNotFound();
        $this->apiAs($foreign, 'POST', '/api/v1/document-requests', ['template_id' => $t['id'], 'answers' => ['addressee' => 'X']])->assertStatus(422);
        $this->assertSame([], $this->apiAs($foreign, 'GET', '/api/v1/document-requests')->json('data'));
    }

    public function test_long_answers_survive_storage(): void
    {
        $t = $this->template(['name' => 'Long', 'requires_manager' => false, 'fields' => [
            ['key' => 'addressee', 'label' => 'To', 'type' => 'text', 'required' => true],
            ...array_map(fn ($i) => ['key' => "note$i", 'label' => "Note $i", 'type' => 'textarea'], range(1, 9)),
        ]]);
        $answers = ['addressee' => 'X'] + array_combine(array_map(fn ($i) => "note$i", range(1, 9)), array_fill(0, 9, str_repeat('😀', 2000)));
        $r = $this->submit($this->employee, $t, $answers);
        $this->assertSame(str_repeat('😀', 2000), $this->apiAs($this->employee, 'GET', "/api/v1/document-requests/{$r['id']}")->json('data.answers.note9'));
    }
}
