<?php

namespace Tests\Feature\Audit;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Country\CountryTestCase;

abstract class AuditTestCase extends CountryTestCase
{
    protected function entries(?Tenant $tenant, array $where = []): Collection
    {
        return DB::table('audit_logs')->where('tenant_id', $tenant?->id)->where($where)->orderBy('id')->get();
    }

    protected function tokenLogin(string $email, string $password)
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->postJson('/api/v1/auth/tokens', ['email' => $email, 'password' => $password, 'device_name' => 'phpunit']);
    }
}
