<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\DeletionRequest;
use App\Models\Employee;
use App\Models\Subscription;
use App\Models\SupportGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\SecurityTestCase;
use ZipArchive;

class PlatformTest extends SecurityTestCase
{
    private Tenant $tenant;

    private Tenant $other;

    private User $owner;

    private User $hr;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('local');
        $this->tenant = $this->makeTenant(['name' => 'Acme Corp']);
        $this->other = $this->makeTenant(['name' => 'Other Inc']);
        $this->owner = $this->makeUser($this->tenant, 'organization-owner');
        $this->hr = $this->makeUser($this->tenant, 'hr-administrator');
        $this->staff = $this->makeUser(null, 'platform-admin');
        $employee = $this->makeEmployee($this->tenant);
        $this->makeCompensation($employee, '123456.00');
        $this->makeEmployee($this->other);
    }

    private function code(User $user): array
    {
        User::query()->withoutGlobalScopes()->whereKey($user->id)->update(['mfa_last_timestep' => null]);

        return ['code' => $this->totp($user->fresh())];
    }

    private function grant(array $data = []): array
    {
        return $this->apiAs($this->owner, 'POST', '/api/v1/account/support-grants', $data + ['hours' => 4, 'reason' => 'Ticket 123'])->assertCreated()->json('data');
    }

    public function test_support_access_requires_an_owner_grant_and_is_read_only_and_audited(): void
    {
        $base = "/api/v1/platform/tenants/{$this->tenant->public_id}/support";
        $this->apiAs($this->staff, 'GET', "$base/employees")->assertForbidden();
        $this->apiAs($this->staff, 'GET', $base)->assertOk()->assertJsonPath('data.active', false);

        $this->apiAs($this->hr, 'POST', '/api/v1/account/support-grants', ['hours' => 4, 'reason' => 'x'])->assertForbidden();
        $this->apiAs($this->owner, 'POST', '/api/v1/account/support-grants', ['hours' => 73, 'reason' => 'x'])->assertUnprocessable();
        $grant = $this->grant();
        $this->apiAs($this->owner, 'POST', '/api/v1/account/support-grants', ['hours' => 4, 'reason' => 'again'])->assertConflict();

        $employees = $this->apiAs($this->staff, 'GET', "$base/employees")->assertOk();
        $this->assertCount(1, $employees->json('data'));
        $this->assertStringNotContainsString('123456', $employees->getContent());
        $this->assertArrayNotHasKey('national_id', $employees->json('data.0'));
        $this->apiAs($this->staff, 'GET', "$base/overview")->assertOk()->assertJsonPath('data.organization.name', 'Acme Corp');
        $this->apiAs($this->staff, 'GET', "$base/payroll")->assertForbidden();
        $this->apiAs($this->staff, 'GET', "$base/secrets")->assertNotFound();
        // A grant from one organization never opens another.
        $this->apiAs($this->staff, 'GET', "/api/v1/platform/tenants/{$this->other->public_id}/support/employees")->assertForbidden();
        // Support never gets write routes or the tenant API itself.
        $this->apiAs($this->staff, 'GET', '/api/v1/employees')->assertForbidden();

        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('event', 'support.accessed')->exists());
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('event', 'support.granted')->exists());

        $this->apiAs($this->owner, 'POST', "/api/v1/account/support-grants/{$grant['id']}/revoke")->assertOk()->assertJsonPath('data.active', false);
        $this->apiAs($this->staff, 'GET', "$base/employees")->assertForbidden();
    }

    public function test_support_access_expires_and_payroll_needs_explicit_consent(): void
    {
        $base = "/api/v1/platform/tenants/{$this->tenant->public_id}/support";
        $this->grant(['include_payroll' => true, 'hours' => 1]);
        $this->apiAs($this->staff, 'GET', "$base/payroll")->assertOk();
        $this->apiAs($this->staff, 'GET', "$base/audit")->assertOk();
        $this->travel(61)->minutes();
        $this->apiAs($this->staff, 'GET', "$base/overview")->assertForbidden();
        $this->assertFalse(SupportGrant::query()->withoutGlobalScopes()->first()->isActive());
    }

    public function test_tenant_users_cannot_reach_the_platform_console(): void
    {
        foreach (['tenants', "tenants/{$this->tenant->public_id}", 'subscriptions/overview', 'usage', 'health', "tenants/{$this->tenant->public_id}/support/overview"] as $path) {
            $this->apiAs($this->owner, 'GET', "/api/v1/platform/$path")->assertForbidden();
        }
    }

    public function test_platform_overview_usage_and_health(): void
    {
        $this->apiAs($this->staff, 'GET', '/api/v1/platform/tenants?search=acme')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.subscription.plan', 'enterprise');
        $detail = $this->apiAs($this->staff, 'GET', "/api/v1/platform/tenants/{$this->tenant->public_id}")->assertOk();
        $detail->assertJsonPath('data.usage.employees.used', 1);
        $this->assertStringNotContainsString('123456', $detail->getContent());
        $this->apiAs($this->staff, 'GET', '/api/v1/platform/subscriptions/overview')->assertOk()->assertJsonPath('data.by_plan.enterprise', 2);
        $this->apiAs($this->staff, 'GET', '/api/v1/platform/usage?sort=employees')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.employees.used', 1);
        $health = $this->apiAs($this->staff, 'GET', '/api/v1/platform/health')->assertOk();
        $health->assertJsonPath('data.checks.database.status', 'ok')->assertJsonPath('data.checks.cache.status', 'ok');
        $this->assertStringNotContainsString((string) config('database.connections.mysql.password') ?: 'never-empty', $health->getContent());
    }

    public function test_platform_staff_extend_trials_assign_manual_plans_and_suspend_with_a_reason(): void
    {
        $this->apiAs($this->staff, 'PATCH', "/api/v1/platform/tenants/{$this->tenant->public_id}/subscription", ['action' => 'extend_trial', 'days' => 1, 'reason' => 'x', 'version' => 1])->assertForbidden();
        $this->staff = $this->makeUser(null, 'platform-owner');
        $sub = Subscription::forTenant($this->tenant);
        $sub->forceFill(['provider' => 'trial', 'status' => 'trialing', 'trial_ends_at' => now()->subDay()])->saveQuietly();
        $url = "/api/v1/platform/tenants/{$this->tenant->public_id}/subscription";
        $this->apiAs($this->staff, 'PATCH', $url, ['action' => 'extend_trial', 'days' => 14, 'reason' => 'Sales', 'version' => $sub->version])->assertOk()
            ->assertJsonPath('data.subscription.access.mode', 'full');
        $this->apiAs($this->staff, 'PATCH', $url, ['action' => 'extend_trial', 'days' => 14, 'reason' => 'Sales', 'version' => $sub->version])->assertConflict();
        $sub->refresh();
        $this->apiAs($this->staff, 'PATCH', $url, ['action' => 'assign_plan', 'plan' => 'growth', 'reason' => 'Invoiced', 'version' => $sub->version])->assertOk()
            ->assertJsonPath('data.subscription.plan.code', 'growth')->assertJsonPath('data.subscription.provider', 'manual');
        $sub->refresh()->forceFill(['provider' => 'stripe', 'status' => 'active', 'stripe_subscription_id' => 'sub_1'])->saveQuietly();
        $this->apiAs($this->staff, 'PATCH', $url, ['action' => 'assign_plan', 'plan' => 'starter', 'reason' => 'x', 'version' => $sub->version])->assertConflict();

        $this->apiAs($this->staff, 'PATCH', "/api/v1/platform/tenants/{$this->tenant->public_id}/status", ['status' => 'suspended'])->assertUnprocessable();
        $this->apiAs($this->staff, 'PATCH', "/api/v1/platform/tenants/{$this->tenant->public_id}/status", ['status' => 'suspended', 'reason' => 'Abuse'])->assertOk();
        $this->assertSame('Abuse', AuditLog::query()->withoutGlobalScopes()->where('event', 'platform.tenant_status_changed')->latest('id')->first()->metadata['reason']);
    }

    public function test_owner_exports_all_organization_data_with_mfa(): void
    {
        $this->apiAs($this->hr, 'POST', '/api/v1/account/exports', $this->code($this->hr))->assertForbidden();
        $this->apiAs($this->owner, 'POST', '/api/v1/account/exports', ['code' => '000000'])->assertUnprocessable();
        // Available even when billing has lapsed to read-only.
        Subscription::forTenant($this->tenant)->forceFill(['provider' => 'trial', 'status' => 'trialing', 'trial_ends_at' => now()->subDay()])->saveQuietly();
        $export = $this->apiAs($this->owner, 'POST', '/api/v1/account/exports', $this->code($this->owner))->assertAccepted()->json('data');
        $list = $this->apiAs($this->owner, 'GET', '/api/v1/account/exports')->assertOk()->assertJsonPath('data.0.status', 'ready');
        $this->assertArrayNotHasKey('path', $list->json('data.0'));

        $response = $this->apiAs($this->owner, 'GET', "/api/v1/account/exports/{$export['id']}/download")->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $employees = $zip->getFromName('data/employees.csv');
        $this->assertNotFalse($employees);
        $this->assertSame(2, substr_count(trim($employees), "\n") + 1, 'header plus this organization\'s one employee');
        $this->assertNotFalse($zip->getFromName('data/users.csv'));
        $this->assertStringContainsString('123456', (string) $zip->getFromName('data/employee_compensation.csv'));
        $zip->close();
        unlink($tmp);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('event', 'export.downloaded')->exists());

        $this->travel(8)->days();
        $this->artisan('wingora:exports:expire')->assertSuccessful();
        $this->apiAs($this->owner, 'GET', "/api/v1/account/exports/{$export['id']}/download")->assertStatus(410);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_account_deletion_waits_respects_legal_hold_and_purges_only_that_organization(): void
    {
        $this->apiAs($this->owner, 'POST', '/api/v1/account/deletion', ['confirm_name' => 'Wrong'] + $this->code($this->owner))->assertUnprocessable();
        $this->tenant->forceFill(['audit_legal_hold' => true])->save();
        $this->apiAs($this->owner, 'POST', '/api/v1/account/deletion', ['confirm_name' => 'acme corp'] + $this->code($this->owner))->assertConflict();
        $this->tenant->forceFill(['audit_legal_hold' => false])->save();

        $this->apiAs($this->owner, 'POST', '/api/v1/account/deletion', ['confirm_name' => 'acme corp'] + $this->code($this->owner))->assertCreated();
        $this->apiAs($this->owner, 'POST', '/api/v1/account/deletion/cancel')->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->apiAs($this->owner, 'POST', '/api/v1/account/deletion', ['confirm_name' => 'Acme Corp'] + $this->code($this->owner))->assertCreated();
        $this->apiAs($this->owner, 'GET', '/api/v1/account/deletion')->assertOk()->assertJsonPath('data.request.status', 'scheduled');
        $token = $this->tokenFor($this->owner);

        $this->travel(29)->days();
        $this->artisan('wingora:tenants:purge-deleted')->assertSuccessful();
        $this->assertSame('active', $this->tenant->fresh()->status);

        $this->travel(2)->days();
        $this->artisan('wingora:tenants:purge-deleted')->assertSuccessful();
        $tenant = $this->tenant->fresh();
        $this->assertSame(['deleted', 'Deleted organization'], [$tenant->status, $tenant->name]);
        $this->assertSame(0, Employee::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, Employee::query()->withoutGlobalScopes()->where('tenant_id', $this->other->id)->count());
        $this->assertNotNull(Subscription::forTenant($tenant));
        $this->assertSame('completed', DeletionRequest::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest('id')->value('status'));
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('event', 'account.deletion_completed')->exists());
        $this->apiWithToken($token, 'GET', '/api/v1/organization')->assertUnauthorized();
        Tenancy::forget();
    }
}
