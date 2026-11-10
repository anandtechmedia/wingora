<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditEvents;
use App\Audit\Redactor;
use App\Country\RuleCategory;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class AuditRecordingTest extends AuditTestCase
{
    public function test_authentication_events_record_actor_tenant_outcome_and_request_context(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'employee');

        $failed = $this->tokenLogin($user->email, 'wrong-password');
        $failure = $this->entries($tenant, ['event' => 'auth.login_failed'])->last();
        $this->assertSame('failure', $failure->outcome);
        $this->assertSame('authentication', $failure->category);
        $this->assertSame($user->id, (int) $failure->actor_user_id);
        $this->assertSame('unauthenticated', $failure->actor_type);
        $this->assertSame('127.0.0.1', $failure->ip_address);
        $this->assertSame('POST', $failure->http_method);
        $this->assertSame($failed->headers->get('X-Request-Id'), $failure->request_id);
        $this->assertNotNull($failure->created_at);

        $this->tokenLogin($user->email, self::PASSWORD)->assertCreated();
        $this->assertSame('success', $this->entries($tenant, ['event' => 'auth.login_succeeded'])->last()->outcome);
        $issued = $this->entries($tenant, ['event' => 'auth.token_issued'])->last();
        $this->assertSame('api_tokens', $issued->category);
        $this->assertStringNotContainsString('wgr_', (string) $issued->metadata);
    }

    public function test_token_revocation_is_attributed_to_the_signed_in_user(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'employee');

        $this->apiAs($user, 'DELETE', '/api/v1/auth/tokens/current')->assertNoContent();
        $entry = $this->entries($tenant, ['event' => 'auth.token_revoked'])->last();
        $this->assertSame('api_tokens', $entry->category);
        $this->assertSame('user', $entry->actor_type);
        $this->assertSame($user->id, (int) $entry->actor_user_id);
    }

    public function test_employee_profile_changes_keep_before_after_values_without_sensitive_data(): void
    {
        $tenant = $this->makeTenant();
        $employee = $this->makeEmployee($tenant);
        Tenancy::run($tenant, fn () => $employee->forceFill(['job_title' => 'Lead', 'national_id' => '123-45-6789'])->save());

        $entry = $this->entries($tenant, ['event' => 'employee.updated'])->last();
        $this->assertSame('employee_profile', $entry->category);
        $this->assertSame($employee->public_id, $entry->subject_public_id);
        $this->assertSame('Analyst', json_decode($entry->old_values, true)['job_title']);
        $this->assertSame('Lead', json_decode($entry->new_values, true)['job_title']);
        $this->assertSame(Redactor::MASK, json_decode($entry->new_values, true)['national_id']);
        $this->assertStringNotContainsString('123-45-6789', DB::table('audit_logs')->get()->toJson());
    }

    public function test_document_access_records_the_file_but_never_its_contents(): void
    {
        $tenant = $this->makeTenant();
        $payroll = $this->makeUser($tenant, 'payroll-administrator');
        $employee = $this->makeEmployee($tenant);
        $id = $this->apiAs($payroll, 'POST', '/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('payslip.pdf', "%PDF-1.4\nSECRET-PAYSLIP-BODY\n%%EOF"),
            'category' => 'payroll', 'employee_id' => $employee->public_id,
        ])->assertCreated()->json('data.id');

        $this->apiAs($payroll, 'GET', "/api/v1/files/{$id}/download")->assertOk();

        $entry = $this->entries($tenant, ['event' => 'file.downloaded'])->last();
        $this->assertSame('employee_documents', $entry->category);
        $this->assertSame($id, $entry->subject_public_id);
        $this->assertSame('payroll', json_decode($entry->metadata, true)['file_category']);
        $this->assertStringNotContainsString('SECRET-PAYSLIP-BODY', DB::table('audit_logs')->get()->toJson());
    }

    public function test_permission_changes_and_denied_access_are_recorded(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, 'organization-owner');
        $target = $this->makeUser($tenant, 'employee');

        $this->apiAs($owner, 'PUT', "/api/v1/users/{$target->public_id}/roles", ['roles' => ['auditor']])->assertOk();
        $change = $this->entries($tenant, ['event' => 'user.roles_changed'])->last();
        $this->assertSame('permissions', $change->category);
        $this->assertSame(['roles' => ['employee']], json_decode($change->old_values, true));

        $employee = $this->makeUser($tenant, 'employee');
        $this->apiAs($employee, 'GET', '/api/v1/audit-logs')->assertForbidden();
        $denied = $this->entries($tenant, ['event' => AuditEvents::ACCESS_DENIED])->last();
        $this->assertSame('failure', $denied->outcome);
        $this->assertSame('authorization', $denied->category);
        $this->assertSame($employee->id, (int) $denied->actor_user_id);
        $this->assertSame('/api/v1/audit-logs', json_decode($denied->metadata, true)['path']);
    }

    public function test_country_rule_changes_are_recorded_on_the_platform_trail(): void
    {
        $version = $this->approvedRule('US', null, RuleCategory::Compliance, ['requirements' => [['key' => 'form_i9', 'name' => 'I-9']]]);

        $events = $this->entries(null, ['category' => 'country_rules'])->pluck('event')->all();
        $this->assertContains('rule_version.created', $events);
        $this->assertContains('country_rules.approved', $events);
        $this->assertTrue($this->entries(null, ['subject_public_id' => $version->public_id])->isNotEmpty());
    }

    public function test_administrative_operations_are_recorded(): void
    {
        $platform = $this->makeUser(null, 'platform-owner');
        $id = $this->apiAs($platform, 'POST', '/api/v1/platform/tenants', [
            'name' => 'Acme', 'slug' => 'acme-audit', 'country_code' => 'US', 'owner_name' => 'Owner', 'owner_email' => 'owner@acme-audit.test',
        ])->assertCreated()->json('data.id');

        $tenant = Tenant::query()->where('public_id', $id)->firstOrFail();
        $created = $this->entries($tenant, ['event' => 'tenant.created'])->last();
        $this->assertSame('administration', $created->category);
        $this->assertSame($platform->id, (int) $created->actor_user_id);
    }

    public function test_events_from_future_hr_modules_land_in_their_categories(): void
    {
        $tenant = $this->makeTenant();
        Tenancy::run($tenant, function () {
            AuditLog::record(AuditEvents::LEAVE_REQUEST_APPROVED, metadata: ['days' => 2]);
            AuditLog::record(AuditEvents::ATTENDANCE_ADJUSTED, old: ['clock_in' => '09:10'], new: ['clock_in' => '09:00']);
            AuditLog::record(AuditEvents::PAYROLL_RUN_PREPARED);
            AuditLog::record(AuditEvents::PAYROLL_RUN_APPROVED);
            AuditLog::record(AuditEvents::DATA_EXPORTED, metadata: ['dataset' => 'employees', 'rows' => 10]);
        });

        $categories = $this->entries($tenant)->pluck('category', 'event');
        $this->assertSame('leave', $categories[AuditEvents::LEAVE_REQUEST_APPROVED]);
        $this->assertSame('attendance', $categories[AuditEvents::ATTENDANCE_ADJUSTED]);
        $this->assertSame('payroll', $categories[AuditEvents::PAYROLL_RUN_PREPARED]);
        $this->assertSame('payroll', $categories[AuditEvents::PAYROLL_RUN_APPROVED]);
        $this->assertSame('data_export', $categories[AuditEvents::DATA_EXPORTED]);
    }

    public function test_redactor_removes_secrets_and_identity_documents(): void
    {
        $clean = Redactor::clean([
            'password' => 'hunter2', 'new_password' => 'x', 'token_id' => 42, 'api_token' => 'abc',
            'profile' => ['national_id' => 'A123', 'passport_number' => 'P1', 'name' => 'Ana'],
            'header' => 'Bearer abc.def.ghi', 'raw' => 'wgr_'.str_repeat('a', 40), 'document_number' => '784-1990',
        ]);

        $this->assertSame(Redactor::MASK, $clean['password']);
        $this->assertSame(Redactor::MASK, $clean['new_password']);
        $this->assertSame(42, $clean['token_id']);
        $this->assertSame(Redactor::MASK, $clean['api_token']);
        $this->assertSame(['national_id' => Redactor::MASK, 'passport_number' => Redactor::MASK, 'name' => 'Ana'], $clean['profile']);
        $this->assertSame(Redactor::MASK, $clean['header']);
        $this->assertSame(Redactor::MASK, $clean['raw']);
        $this->assertSame(Redactor::MASK, $clean['document_number']);
    }
}
