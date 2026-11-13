<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy;

class AuditRetentionTest extends AuditTestCase
{
    public function test_retention_policies_are_validated_and_restricted(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, 'organization-owner');

        $this->apiAs($this->makeUser($tenant, 'auditor'), 'PUT', '/api/v1/audit-retention', ['policies' => ['authentication' => 400]])->assertForbidden();
        $this->apiAs($this->makeUser($tenant, 'hr-administrator'), 'PUT', '/api/v1/audit-retention', ['legal_hold' => true])->assertForbidden();
        $this->apiAs($owner, 'PUT', '/api/v1/audit-retention', ['policies' => ['authentication' => 30]])->assertUnprocessable();
        $this->apiAs($owner, 'PUT', '/api/v1/audit-retention', ['policies' => ['gossip' => 400]])->assertUnprocessable();

        $policies = collect($this->apiAs($owner, 'PUT', '/api/v1/audit-retention', ['policies' => ['authentication' => 400]])->assertOk()->json('data.policies'))->keyBy('category');
        $this->assertSame(400, $policies['authentication']['retention_days']);
        $this->assertTrue($policies['authentication']['overridden']);
        $this->assertTrue($this->entries($tenant, ['event' => 'audit.retention_changed'])->isNotEmpty());
    }

    public function test_pruning_removes_only_expired_entries_and_keeps_chains_verifiable(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'employee');
        $this->tokenLogin($user->email, 'wrong-password');
        Tenancy::run($tenant, fn () => AuditLog::record('employee.viewed'));
        $oldAuth = $this->entries($tenant, ['category' => 'authentication'])->count();

        $this->travel(800)->days();
        $this->tokenLogin($user->email, 'wrong-password');

        $this->artisan('wingora:audit:prune', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame($oldAuth + 1, $this->entries($tenant, ['category' => 'authentication'])->count());

        $this->artisan('wingora:audit:prune')->assertExitCode(0);
        $this->assertSame(1, $this->entries($tenant, ['category' => 'authentication'])->count());
        $this->assertTrue($this->entries($tenant, ['event' => 'employee.viewed'])->isNotEmpty());

        $pruned = $this->entries($tenant, ['event' => 'audit.retention_pruned'])->last();
        $this->assertSame($oldAuth, json_decode($pruned->metadata, true)['deleted']);
        $this->artisan('wingora:audit:verify')->assertExitCode(0);
    }

    public function test_legal_hold_prevents_pruning(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, 'organization-owner');
        $user = $this->makeUser($tenant, 'employee');
        $this->tokenLogin($user->email, 'wrong-password');
        $this->apiAs($owner, 'PUT', '/api/v1/audit-retention', ['legal_hold' => true])->assertOk()->assertJsonPath('data.legal_hold', true);
        $before = $this->entries($tenant, ['category' => 'authentication'])->count();

        $this->travel(800)->days();
        $this->artisan('wingora:audit:prune')->assertExitCode(0);

        $this->assertSame($before, $this->entries($tenant, ['category' => 'authentication'])->count());
    }
}
