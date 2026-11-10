<?php

namespace Tests\Feature\Security;

use App\Exceptions\TenantContextMissing;
use App\Models\Employee;
use App\Security\RoleCatalog;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use LogicException;

class TenantIsolationTest extends SecurityTestCase
{
    public function test_tenant_a_cannot_read_tenant_b_records_by_id(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $ownerA = $this->makeUser($a, RoleCatalog::ORGANIZATION_OWNER);
        $deptB = $this->makeDepartment($b, 'Finance');
        $empB = $this->makeEmployee($b, $deptB, null, ['national_id' => '123-45-6789']);
        $this->makeCompensation($empB);
        $userB = $this->makeUser($b, RoleCatalog::EMPLOYEE);

        foreach ([
            "/api/v1/employees/{$empB->public_id}",
            "/api/v1/employees/{$empB->public_id}/sensitive",
            "/api/v1/employees/{$empB->public_id}/compensation",
            "/api/v1/departments/{$deptB->public_id}",
            "/api/v1/users/{$userB->public_id}",
        ] as $uri) {
            $this->apiAs($ownerA, 'GET', $uri)->assertNotFound();
        }

        $this->apiAs($ownerA, 'PUT', "/api/v1/users/{$userB->public_id}/roles", ['roles' => ['employee']])->assertNotFound();
        $this->apiAs($ownerA, 'PATCH', "/api/v1/users/{$userB->public_id}/status", ['status' => 'disabled'])->assertNotFound();
        $this->assertSame('active', $userB->fresh()->status);
    }

    public function test_tenant_listings_only_contain_own_records(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $ownerA = $this->makeUser($a, RoleCatalog::ORGANIZATION_OWNER);
        $this->makeEmployee($a, $this->makeDepartment($a, 'Ops'));
        $this->makeEmployee($b, $this->makeDepartment($b, 'Ops'));
        $this->makeUser($b, RoleCatalog::EMPLOYEE);

        $this->apiAs($ownerA, 'GET', '/api/v1/employees')->assertOk()->assertJsonCount(1, 'data');
        $this->apiAs($ownerA, 'GET', '/api/v1/departments')->assertOk()->assertJsonCount(1, 'data');
        $this->apiAs($ownerA, 'GET', '/api/v1/users')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_tenant_cannot_upload_files_against_another_tenants_employee(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $hrA = $this->makeUser($a, RoleCatalog::HR_ADMIN);
        $empB = $this->makeEmployee($b);

        $this->apiAs($hrA, 'POST', '/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'hello'),
            'category' => 'general',
            'employee_id' => $empB->public_id,
        ])->assertNotFound();
    }

    public function test_queries_without_tenant_context_fail_closed(): void
    {
        Tenancy::forget();
        $this->expectException(TenantContextMissing::class);
        Employee::query()->count();
    }

    public function test_creating_tenant_data_without_context_fails_closed(): void
    {
        Tenancy::forget();
        $this->expectException(TenantContextMissing::class);
        (new Employee(['employee_number' => 'X1', 'first_name' => 'A', 'last_name' => 'B']))->save();
    }

    public function test_tenant_id_is_immutable_and_cannot_be_written_cross_tenant(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $emp = $this->makeEmployee($a);

        Tenancy::run($a, function () use ($emp, $b) {
            $this->expectException(LogicException::class);
            $emp->forceFill(['tenant_id' => $b->id])->save();
        });
    }

    public function test_tenant_cannot_be_chosen_by_request_headers_or_parameters(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $ownerA = $this->makeUser($a, RoleCatalog::ORGANIZATION_OWNER);
        $this->makeEmployee($b);

        $this->withHeaders(['X-Tenant-Id' => (string) $b->id, 'X-Tenant' => $b->slug]);
        $this->apiAs($ownerA, 'GET', '/api/v1/employees?tenant_id='.$b->id)->assertOk()->assertJsonCount(0, 'data');
        $this->apiAs($ownerA, 'GET', '/api/v1/organization')->assertOk()->assertJsonPath('data.id', $a->public_id);
    }

    public function test_suspended_tenant_users_are_rejected_and_tokens_revoked(): void
    {
        $a = $this->makeTenant();
        $owner = $this->makeUser($a, RoleCatalog::ORGANIZATION_OWNER);
        $platform = $this->makeUser(null, RoleCatalog::PLATFORM_ADMIN);
        $token = $this->tokenFor($owner);

        $this->apiAs($platform, 'PATCH', "/api/v1/platform/tenants/{$a->public_id}/status", ['status' => 'suspended'])->assertOk();
        $this->apiWithToken($token, 'GET', '/api/v1/organization')->assertUnauthorized();
        $this->assertSame(0, $owner->tokens()->count());
    }
}
