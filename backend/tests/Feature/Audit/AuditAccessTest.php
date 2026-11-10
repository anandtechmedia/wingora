<?php

namespace Tests\Feature\Audit;

use App\Country\RuleCategory;
use App\Models\AuditLog;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

class AuditAccessTest extends AuditTestCase
{
    public function test_only_auditors_and_administrators_can_read_and_only_auditors_owners_export(): void
    {
        $tenant = $this->makeTenant();

        foreach (['employee', 'department-manager', 'hr-manager', 'payroll-administrator'] as $role) {
            $this->apiAs($this->makeUser($tenant, $role), 'GET', '/api/v1/audit-logs')->assertForbidden();
        }
        foreach (['auditor', 'hr-administrator', 'organization-owner'] as $role) {
            $this->apiAs($this->makeUser($tenant, $role), 'GET', '/api/v1/audit-logs')->assertOk();
        }

        $this->apiAs($this->makeUser($tenant, 'hr-administrator'), 'GET', '/api/v1/audit-logs/export?format=csv')->assertForbidden();
        $this->apiAs($this->makeUser($tenant, 'auditor'), 'GET', '/api/v1/audit-logs/export?format=csv')->assertOk();
        $this->apiAs($this->makeUser($tenant, 'organization-owner'), 'GET', '/api/v1/audit-logs/export?format=json')->assertOk();
    }

    public function test_tenants_only_see_their_own_audit_trail(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $this->makeEmployee($b);
        $auditorA = $this->makeUser($a, 'auditor');
        $foreign = DB::table('audit_logs')->where('tenant_id', $b->id)->value('public_id');

        $ids = array_column($this->apiAs($auditorA, 'GET', '/api/v1/audit-logs?per_page=100')->json('data'), 'id');
        $this->assertNotContains($foreign, $ids);
        $this->assertSame([], array_intersect($ids, DB::table('audit_logs')->where('tenant_id', '!=', $a->id)->orWhereNull('tenant_id')->pluck('public_id')->all()));

        $this->apiAs($auditorA, 'GET', "/api/v1/audit-logs/{$foreign}")->assertNotFound();
        $csv = $this->apiAs($auditorA, 'GET', '/api/v1/audit-logs/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($foreign, $csv);

        $total = $this->apiAs($auditorA, 'GET', '/api/v1/audit-logs/summary')->json('data.total');
        $this->assertLessThanOrEqual(DB::table('audit_logs')->where('tenant_id', $a->id)->count(), $total);
        $this->assertGreaterThan(0, $total);
    }

    public function test_platform_staff_see_platform_entries_only(): void
    {
        $tenant = $this->makeTenant();
        $this->makeEmployee($tenant);
        $admin = $this->makeUser(null, 'platform-admin');
        $owner = $this->makeUser(null, 'platform-owner');
        $this->approvedRule('MX', null, RuleCategory::Compliance, ['requirements' => [['key' => 'rfc', 'name' => 'RFC']]]);

        $this->apiAs($admin, 'GET', '/api/v1/audit-logs')->assertForbidden();
        $ids = array_column($this->apiAs($admin, 'GET', '/api/v1/platform/audit-logs?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertNotEmpty($ids);
        $this->assertSame([], array_intersect($ids, DB::table('audit_logs')->whereNotNull('tenant_id')->pluck('public_id')->all()));

        $this->apiAs($admin, 'GET', '/api/v1/platform/audit-logs/export?format=csv')->assertForbidden();
        $this->apiAs($owner, 'GET', '/api/v1/platform/audit-logs/export?format=csv')->assertOk();
        $this->apiAs($this->makeUser($tenant, 'organization-owner'), 'GET', '/api/v1/platform/audit-logs')->assertForbidden();
    }

    public function test_search_filters(): void
    {
        $tenant = $this->makeTenant();
        $auditor = $this->makeUser($tenant, 'auditor');
        $user = $this->makeUser($tenant, 'employee');
        $this->tokenLogin($user->email, 'wrong-password');
        $this->tokenLogin($user->email, self::PASSWORD);

        $failures = $this->apiAs($auditor, 'GET', '/api/v1/audit-logs?category=authentication&outcome=failure')->assertOk()->json('data');
        $this->assertSame(['auth.login_failed'], array_values(array_unique(array_column($failures, 'event'))));

        $byActor = $this->apiAs($auditor, 'GET', "/api/v1/audit-logs?actor_id={$user->public_id}&event=auth.*")->json('data');
        $this->assertNotEmpty($byActor);
        $this->assertTrue(collect($byActor)->every(fn ($e) => $e['actor']['id'] === $user->public_id && str_starts_with($e['event'], 'auth.')));

        $requestId = $failures[0]['request_id'];
        $this->assertCount(1, $this->apiAs($auditor, 'GET', "/api/v1/audit-logs?request_id={$requestId}&category=authentication")->json('data'));
        $this->assertSame([], $this->apiAs($auditor, 'GET', '/api/v1/audit-logs?from=2099-01-01&to=2099-12-31')->json('data'));
        $this->apiAs($auditor, 'GET', "/api/v1/audit-logs?event=x' OR 1=1")->assertUnprocessable();

        $entry = $this->apiAs($auditor, 'GET', "/api/v1/audit-logs/{$failures[0]['id']}")->assertOk()->json('data');
        $this->assertArrayHasKey('hash', $entry);
        $this->assertArrayNotHasKey('tenant_id', $entry);
    }

    public function test_csv_export_neutralises_formulas_and_is_itself_audited(): void
    {
        $tenant = $this->makeTenant();
        $auditor = $this->makeUser($tenant, 'auditor');
        Tenancy::run($tenant, fn () => AuditLog::record('employee.viewed', metadata: ['note' => 'x'], new: ['job_title' => '=HYPERLINK("http://evil")']));

        $response = $this->apiAs($auditor, 'GET', '/api/v1/audit-logs/export?format=csv&category=employee_profile')->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('HYPERLINK', $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|,)"?=HYPERLINK/m', $csv);

        $export = $this->entries($tenant, ['event' => 'audit.exported'])->last();
        $this->assertSame('data_export', $export->category);
        $this->assertEquals(['format' => 'csv', 'filters' => ['category' => 'employee_profile'], 'rows' => 1], json_decode($export->metadata, true));

        $json = json_decode($this->apiAs($auditor, 'GET', '/api/v1/audit-logs/export?format=json&category=employee_profile')->streamedContent(), true);
        $this->assertSame('employee.viewed', $json[0]['event']);
    }
}
