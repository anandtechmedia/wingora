<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use LogicException;

class AuditIntegrityTest extends AuditTestCase
{
    private function activeTenant(): array
    {
        $tenant = $this->makeTenant();
        $auditor = $this->makeUser($tenant, 'auditor');
        foreach (range(1, 3) as $i) {
            Tenancy::run($tenant, fn () => AuditLog::record('employee.viewed', metadata: ['i' => $i]));
        }

        return [$tenant, $auditor];
    }

    public function test_untampered_chains_verify(): void
    {
        [$tenant, $auditor] = $this->activeTenant();

        $this->apiAs($auditor, 'GET', '/api/v1/audit-logs/integrity')->assertOk()->assertJsonPath('data.valid', true);
        $this->artisan('wingora:audit:verify', ['--tenant' => $tenant->public_id])->assertExitCode(0);
    }

    public function test_direct_database_edits_are_detected(): void
    {
        [$tenant, $auditor] = $this->activeTenant();
        $target = $this->entries($tenant, ['event' => 'employee.viewed'])->get(1);
        DB::table('audit_logs')->where('id', $target->id)->update(['metadata' => json_encode(['i' => 99])]);

        $result = $this->apiAs($auditor, 'GET', '/api/v1/audit-logs/integrity')->assertOk()->assertJsonPath('data.valid', false)->json('data.chains');
        $broken = collect($result)->firstWhere('category', 'employee_profile');
        $this->assertStringContainsString("Entry {$target->sequence} was modified", implode(' ', $broken['errors']));
        $this->artisan('wingora:audit:verify', ['--tenant' => $tenant->public_id])->assertExitCode(1);
    }

    public function test_deleted_middle_and_newest_entries_are_detected(): void
    {
        [$tenant, $auditor] = $this->activeTenant();
        $rows = $this->entries($tenant, ['event' => 'employee.viewed']);

        DB::table('audit_logs')->where('id', $rows->get(1)->id)->delete();
        $this->assertFalse($this->apiAs($auditor, 'GET', '/api/v1/audit-logs/integrity')->json('data.valid'));
    }

    public function test_truncating_the_newest_entry_is_detected(): void
    {
        [$tenant, $auditor] = $this->activeTenant();
        DB::table('audit_logs')->where('id', $this->entries($tenant, ['event' => 'employee.viewed'])->last()->id)->delete();

        $chains = collect($this->apiAs($auditor, 'GET', '/api/v1/audit-logs/integrity')->json('data.chains'));
        $this->assertStringContainsString('newest entries', implode(' ', $chains->firstWhere('category', 'employee_profile')['errors']));
    }

    public function test_application_cannot_modify_or_delete_entries(): void
    {
        [$tenant] = $this->activeTenant();

        Tenancy::run($tenant, function () {
            foreach ([
                fn () => AuditLog::query()->update(['event' => 'x']),
                fn () => AuditLog::query()->delete(),
                fn () => AuditLog::query()->first()->delete(),
                fn () => AuditLog::query()->first()->forceFill(['event' => 'x'])->save(),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Audit modification was not blocked.');
                } catch (LogicException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
    }
}
