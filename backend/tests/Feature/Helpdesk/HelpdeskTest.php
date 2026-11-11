<?php

namespace Tests\Feature\Helpdesk;

use App\Audit\AuditCategory;
use App\Models\AuditLog;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\SecurityTestCase;

class HelpdeskTest extends SecurityTestCase
{
    private Tenant $tenant;

    private User $admin;

    private User $agent;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('security.files.disk'));
        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeUser($this->tenant, 'hr-administrator');
        $this->agent = $this->makeUser($this->tenant, 'hr-manager');
        $this->employee = $this->makeUser($this->tenant, 'employee');
    }

    private function category(array $attrs = []): array
    {
        return $this->apiAs($this->admin, 'POST', '/api/v1/helpdesk/categories', ['name' => 'Benefits'] + $attrs)->assertCreated()->json('data');
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('note.pdf', "%PDF-1.4\nnote\n%%EOF");
    }

    public function test_ticket_lifecycle_internal_notes_and_attachments(): void
    {
        $cat = $this->category();
        $ticket = $this->apiAs($this->employee, 'POST', '/api/v1/helpdesk/tickets', ['category_id' => $cat['id'], 'subject' => 'Dental plan question',
            'description' => 'Is orthodontics covered?', 'priority' => 'high', 'files' => [$this->pdf()]])->assertCreated()->json('data');
        $this->assertSame(1, $ticket['number']);
        $this->assertSame('open', $ticket['status']);
        $this->assertNotNull($ticket['due_at']);
        $this->assertSame(['closed'], $ticket['next']);
        $this->assertNotSame('Is orthodontics covered?', DB::table('helpdesk_tickets')->value('description'), 'description is encrypted at rest');
        $this->assertSame(2, $this->apiAs($this->employee, 'POST', '/api/v1/helpdesk/tickets', ['category_id' => $cat['id'], 'subject' => 'Second', 'description' => 'More'])->json('data.number'));

        // Agents see the queue; the employee cannot assign, change status or add internal notes.
        $this->assertContains($ticket['id'], array_column($this->apiAs($this->agent, 'GET', '/api/v1/helpdesk/tickets')->json('data'), 'id'));
        $this->apiAs($this->employee, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/assign", ['assignee_id' => $this->agent->public_id, 'version' => 1])->assertForbidden();
        $this->apiAs($this->employee, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/comments", ['body' => 'secret', 'internal' => true])->assertForbidden();
        $this->apiAs($this->employee, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/status", ['status' => 'resolved', 'version' => 1])->assertStatus(409);

        $this->apiAs($this->agent, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/assign", ['assignee_id' => $this->agent->public_id, 'version' => 1])
            ->assertOk()->assertJsonPath('data.assignee.id', $this->agent->public_id);
        $this->apiAs($this->agent, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/status", ['status' => 'in_progress', 'version' => 1])->assertStatus(409);
        $this->apiAs($this->agent, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/comments", ['body' => 'Check plan B rider', 'internal' => true, 'files' => [$this->pdf()]])->assertCreated();
        $this->apiAs($this->agent, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/comments", ['body' => 'Could you send your plan card?'])->assertCreated()
            ->assertJsonPath('data.first_response_at', fn ($v) => $v !== null);
        $this->assertSame(3, $this->apiAs($this->agent, 'GET', "/api/v1/helpdesk/tickets/{$ticket['id']}")->json('data.version'), 'first response bumps the version; internal notes do not');
        $waiting = $this->apiAs($this->agent, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/status", ['status' => 'waiting', 'version' => 3])->assertOk()->json('data');

        $mine = $this->apiAs($this->employee, 'GET', "/api/v1/helpdesk/tickets/{$ticket['id']}")->assertOk()->json('data');
        $this->assertSame(['Could you send your plan card?'], array_column($mine['comments'], 'body'));
        $this->assertCount(1, $mine['attachments']);
        $staffView = $this->apiAs($this->agent, 'GET', "/api/v1/helpdesk/tickets/{$ticket['id']}")->json('data');
        $this->assertCount(2, $staffView['comments']);
        $internalFile = $staffView['comments'][0]['attachments'][0]['id'];
        $this->apiAs($this->employee, 'GET', "/api/v1/helpdesk/attachments/{$internalFile}/download")->assertNotFound();
        $this->apiAs($this->agent, 'GET', "/api/v1/helpdesk/attachments/{$internalFile}/download")->assertOk();
        $this->apiAs($this->employee, 'GET', "/api/v1/helpdesk/attachments/{$mine['attachments'][0]['id']}/download")->assertOk();
        $file = StoredFile::withoutGlobalScopes()->where('category', 'helpdesk')->first();
        $this->apiAs($this->employee, 'GET', "/api/v1/files/{$file->public_id}/download")->assertForbidden();

        // A requester reply moves a waiting ticket back to in progress.
        $this->apiAs($this->employee, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/comments", ['body' => 'Attached'])->assertCreated()->assertJsonPath('data.status', 'in_progress');
        $v = $this->apiAs($this->agent, 'GET', "/api/v1/helpdesk/tickets/{$ticket['id']}")->json('data.version');
        $this->apiAs($this->agent, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/status", ['status' => 'resolved', 'version' => $waiting['version']])->assertStatus(409);
        $this->apiAs($this->agent, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/status", ['status' => 'resolved', 'version' => $v])->assertOk();
        $reopened = $this->apiAs($this->employee, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/status", ['status' => 'open', 'version' => $v + 1])->assertOk()->json('data');
        $this->apiAs($this->employee, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/status", ['status' => 'closed', 'version' => $reopened['version']])->assertOk();
        $this->apiAs($this->employee, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/comments", ['body' => 'late'])->assertStatus(409);

        $events = AuditLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('event', 'like', 'helpdesk.%')->pluck('event');
        $this->assertContains('helpdesk.ticket_opened', $events);
        $this->assertContains('helpdesk.ticket_assigned', $events);
        $this->assertContains('helpdesk.attachment_viewed', $events);
        $this->assertSame(AuditCategory::Helpdesk, AuditLog::withoutGlobalScopes()->where('event', 'helpdesk.ticket_opened')->first()->category);
        $this->assertStringNotContainsString('orthodontics', AuditLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->pluck('new_values')->toJson());
    }

    public function test_restricted_categories_are_limited_to_listed_agents(): void
    {
        $listed = $this->makeUser($this->tenant, 'hr-manager');
        $cat = $this->category(['name' => 'Workplace conduct', 'restricted' => true, 'agent_ids' => [$listed->public_id]]);
        $ticket = $this->apiAs($this->employee, 'POST', '/api/v1/helpdesk/tickets', ['category_id' => $cat['id'], 'subject' => 'Report', 'description' => 'Details'])->assertCreated()->json('data');

        $this->apiAs($this->agent, 'GET', "/api/v1/helpdesk/tickets/{$ticket['id']}")->assertNotFound();
        $this->assertSame([], $this->apiAs($this->agent, 'GET', '/api/v1/helpdesk/tickets')->json('data'));
        $this->apiAs($listed, 'GET', "/api/v1/helpdesk/tickets/{$ticket['id']}")->assertOk();
        $this->apiAs($this->admin, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/assign", ['assignee_id' => $this->agent->public_id, 'version' => 1])->assertNotFound();
        $this->apiAs($listed, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/assign", ['assignee_id' => $this->agent->public_id, 'version' => 1])->assertStatus(422);
        $this->apiAs($listed, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/assign", ['assignee_id' => $listed->public_id, 'version' => 1])->assertOk();
    }

    public function test_tenants_roles_and_agents_are_isolated(): void
    {
        $cat = $this->category();
        $ticket = $this->apiAs($this->employee, 'POST', '/api/v1/helpdesk/tickets', ['category_id' => $cat['id'], 'subject' => 'Payslip', 'description' => 'Question'])->json('data');
        $colleague = $this->makeUser($this->tenant, 'employee');
        $auditor = $this->makeUser($this->tenant, 'auditor');
        $other = $this->makeTenant();
        $otherAdmin = $this->makeUser($other, 'hr-administrator');

        $this->apiAs($colleague, 'GET', "/api/v1/helpdesk/tickets/{$ticket['id']}")->assertNotFound();
        $this->assertSame([], $this->apiAs($colleague, 'GET', '/api/v1/helpdesk/tickets')->json('data'));
        $this->apiAs($auditor, 'GET', '/api/v1/helpdesk/tickets')->assertForbidden();
        $this->apiAs($auditor, 'POST', '/api/v1/helpdesk/tickets', ['category_id' => $cat['id'], 'subject' => 'x', 'description' => 'y'])->assertForbidden();
        $this->apiAs($otherAdmin, 'GET', "/api/v1/helpdesk/tickets/{$ticket['id']}")->assertNotFound();
        $this->apiAs($otherAdmin, 'POST', '/api/v1/helpdesk/tickets', ['category_id' => $cat['id'], 'subject' => 'xyz', 'description' => 'y'])->assertStatus(422);
        $this->apiAs($this->agent, 'PATCH', '/api/v1/helpdesk/settings', ['due_hours' => ['urgent' => 2]])->assertForbidden();
        $this->apiAs($this->admin, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/assign", ['assignee_id' => $colleague->public_id, 'version' => 1])->assertStatus(422);
        $this->apiAs($this->admin, 'POST', "/api/v1/helpdesk/tickets/{$ticket['id']}/assign", ['assignee_id' => $otherAdmin->public_id, 'version' => 1])->assertStatus(422);

        // Priority changes recompute the due-by time from the configured targets.
        $this->apiAs($this->admin, 'PATCH', '/api/v1/helpdesk/settings', ['due_hours' => ['low' => 100, 'normal' => 50, 'high' => 10, 'urgent' => 2]])->assertOk();
        $updated = $this->apiAs($this->agent, 'PATCH', "/api/v1/helpdesk/tickets/{$ticket['id']}", ['priority' => 'urgent', 'version' => 1])->assertOk()->json('data');
        $this->assertEqualsWithDelta(2 * 3600, strtotime($updated['due_at']) - strtotime($updated['created_at']), 5);
        $this->apiAs($this->agent, 'PATCH', "/api/v1/helpdesk/tickets/{$ticket['id']}", ['priority' => 'low', 'version' => 1])->assertStatus(409);
    }

    public function test_knowledge_base_drafts_are_staff_only(): void
    {
        $faq = $this->apiAs($this->agent, 'POST', '/api/v1/helpdesk/articles', ['kind' => 'faq', 'title' => 'How do I change my bank account?', 'body' => 'Open My profile.'])->assertCreated()->json('data');
        $this->apiAs($this->employee, 'POST', '/api/v1/helpdesk/articles', ['kind' => 'faq', 'title' => 'Mine', 'body' => 'x y z'])->assertForbidden();
        $this->assertSame([], $this->apiAs($this->employee, 'GET', '/api/v1/helpdesk/articles')->json('data'));
        $this->apiAs($this->employee, 'GET', "/api/v1/helpdesk/articles/{$faq['id']}")->assertNotFound();
        $this->apiAs($this->agent, 'POST', "/api/v1/helpdesk/articles/{$faq['id']}/publish")->assertOk();
        $list = $this->apiAs($this->employee, 'GET', '/api/v1/helpdesk/articles?q=bank')->assertOk()->json('data');
        $this->assertSame([$faq['id']], array_column($list, 'id'));
        $this->assertSame('Open My profile.', $list[0]['body']);
        $this->assertSame([], $this->apiAs($this->employee, 'GET', '/api/v1/helpdesk/articles?q=payslip')->json('data'));
        $other = $this->makeUser($this->makeTenant(), 'employee');
        $this->apiAs($other, 'GET', "/api/v1/helpdesk/articles/{$faq['id']}")->assertNotFound();
    }
}
