<?php

namespace Tests\Feature\Assets;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\Security\SecurityTestCase;

class AssetsTest extends SecurityTestCase
{
    private Tenant $tenant;

    private User $admin;

    private User $manager;

    private User $employee;

    private Employee $employeeRecord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeUser($this->tenant, 'hr-administrator');
        $this->manager = $this->makeUser($this->tenant, 'department-manager');
        $this->employee = $this->makeUser($this->tenant, 'employee');
        $this->makeEmployee($this->tenant, null, $this->admin);
        $this->makeEmployee($this->tenant, null, $this->manager);
        $this->employeeRecord = $this->makeEmployee($this->tenant, null, $this->employee);
    }

    private function category(string $name = 'Laptops'): array
    {
        return $this->apiAs($this->admin, 'POST', '/api/v1/assets/categories', ['name' => $name])->assertCreated()->json('data');
    }

    private function asset(array $overrides = []): array
    {
        $overrides['category_id'] ??= $this->category()['id'];

        return $this->apiAs($this->admin, 'POST', '/api/v1/assets', $overrides + [
            'asset_tag' => 'LT-001', 'name' => 'MacBook Pro 14', 'serial_number' => 'C02XYZ', 'purchased_on' => '2027-01-02',
            'purchase_cost' => '2399.00', 'currency' => 'USD', 'warranty_ends_on' => '2030-01-02',
        ])->assertCreated()->json('data');
    }

    public function test_purchase_cost_and_currency_stay_paired_on_update(): void
    {
        $asset = $this->asset();
        $url = "/api/v1/assets/{$asset['id']}";
        $v = $this->apiAs($this->admin, 'PATCH', $url, ['purchase_cost' => '2100.00', 'version' => $asset['version']])->assertOk()->json('data');
        $this->assertSame(['2100.00', 'USD'], [$v['purchase_cost'], $v['currency']]);
        $this->apiAs($this->admin, 'PATCH', $url, ['purchase_cost' => null, 'version' => $v['version']])->assertStatus(422)->assertJsonValidationErrors('purchase_cost');
        $this->apiAs($this->admin, 'PATCH', $url, ['currency' => null, 'version' => $v['version']])->assertStatus(422)->assertJsonValidationErrors('currency');
        $cleared = $this->apiAs($this->admin, 'PATCH', $url, ['purchase_cost' => null, 'currency' => null, 'version' => $v['version']])->assertOk()->json('data');
        $this->assertSame([null, null], [$cleared['purchase_cost'], $cleared['currency']]);
    }

    public function test_assignment_lifecycle_history_and_audit(): void
    {
        $asset = $this->asset();
        $this->assertSame('available', $asset['status']);

        $assigned = $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/assign", [
            'employee_id' => $this->employeeRecord->public_id, 'assigned_on' => '2027-02-01', 'due_back_on' => '2028-02-01', 'version' => $asset['version'],
        ])->assertOk()->json('data');
        $this->assertSame('assigned', $assigned['status']);
        $this->assertSame($this->employeeRecord->public_id, $assigned['assigned_to']['id']);

        $this->apiAs($this->admin, 'GET', '/api/v1/assets?status=assigned&q=macbook')->assertOk()->assertJsonPath('meta.total', 1);
        $this->apiAs($this->admin, 'GET', "/api/v1/assets?employee={$this->employeeRecord->public_id}")->assertJsonPath('meta.total', 1);
        $mine = $this->apiAs($this->employee, 'GET', '/api/v1/assets/mine')->assertOk()->json('data');
        $this->assertSame('LT-001', $mine[0]['asset']['asset_tag']);
        $this->assertNull($mine[0]['returned_on']);

        $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/return", ['condition' => 'fair', 'returned_on' => '2027-01-15', 'version' => $assigned['version']])
            ->assertStatus(422)->assertJsonValidationErrors('returned_on');
        $returned = $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/return", ['condition' => 'fair', 'returned_on' => '2027-06-30', 'notes' => 'Scratched lid', 'version' => $assigned['version']])
            ->assertOk()->json('data');
        $this->assertSame(['available', 'fair', null], [$returned['status'], $returned['condition'], $returned['assigned_to']]);

        $history = $this->apiAs($this->admin, 'GET', "/api/v1/assets/{$asset['id']}/history")->assertOk()->json('data');
        $this->assertSame(['good', 'fair', '2027-06-30'], [$history['assignments'][0]['condition_out'], $history['assignments'][0]['condition_in'], $history['assignments'][0]['returned_on']]);
        $this->assertSame('2027-06-30', $this->apiAs($this->employee, 'GET', '/api/v1/assets/mine')->json('data.0.returned_on'));

        foreach (['assets.category_created', 'assets.asset_created', 'assets.assignment_created', 'assets.assignment_updated', 'assets.asset_updated'] as $event) {
            $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', $event)->where('category', 'assets')->exists(), $event);
        }
    }

    public function test_validation_and_concurrency(): void
    {
        $category = $this->category();
        $asset = $this->asset(['category_id' => $category['id']]);
        $this->apiAs($this->admin, 'POST', '/api/v1/assets', ['category_id' => $category['id'], 'asset_tag' => 'LT-001', 'name' => 'Duplicate'])->assertStatus(422)->assertJsonValidationErrors('asset_tag');
        $this->apiAs($this->admin, 'POST', '/api/v1/assets', ['category_id' => $category['id'], 'asset_tag' => 'LT-002', 'name' => 'X', 'purchased_on' => '2027-05-01', 'warranty_ends_on' => '2027-04-01'])->assertStatus(422)->assertJsonValidationErrors('warranty_ends_on');
        $this->apiAs($this->admin, 'POST', '/api/v1/assets', ['category_id' => $category['id'], 'asset_tag' => 'LT-003', 'name' => 'X', 'purchase_cost' => '10'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->apiAs($this->admin, 'POST', '/api/v1/assets/categories', ['name' => 'Laptops'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->apiAs($this->admin, 'PATCH', "/api/v1/assets/{$asset['id']}", ['warranty_ends_on' => '2026-01-01', 'version' => $asset['version']])->assertStatus(422)->assertJsonValidationErrors('warranty_ends_on');

        $this->apiAs($this->admin, 'PATCH', "/api/v1/assets/categories/{$category['id']}", ['active' => false, 'version' => $category['version']])->assertOk();
        $this->apiAs($this->admin, 'POST', '/api/v1/assets', ['category_id' => $category['id'], 'asset_tag' => 'LT-004', 'name' => 'X'])->assertStatus(422)->assertJsonValidationErrors('category_id');

        $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/assign", ['employee_id' => $this->employeeRecord->public_id, 'assigned_on' => '2027-03-01', 'due_back_on' => '2027-02-01', 'version' => $asset['version']])
            ->assertStatus(422)->assertJsonValidationErrors('due_back_on');
        $assigned = $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/assign", ['employee_id' => $this->employeeRecord->public_id, 'version' => $asset['version']])->assertOk()->json('data');
        $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/assign", ['employee_id' => $this->employeeRecord->public_id, 'version' => $asset['version']])->assertStatus(409);
        $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/assign", ['employee_id' => $this->employeeRecord->public_id, 'version' => $assigned['version']])->assertStatus(422);
        $this->apiAs($this->admin, 'PATCH', "/api/v1/assets/{$asset['id']}", ['status' => 'retired', 'version' => $assigned['version']])->assertStatus(422);
    }

    public function test_maintenance_takes_asset_out_of_service_and_retirement_blocks_work(): void
    {
        $asset = $this->asset();
        $record = $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/maintenance", ['kind' => 'repair', 'description' => 'Battery swap', 'vendor' => 'Apple', 'cost' => '199.00', 'currency' => 'USD', 'status' => 'in_progress'])
            ->assertCreated()->json('data');
        $current = $this->apiAs($this->admin, 'GET', "/api/v1/assets/{$asset['id']}")->assertJsonPath('data.status', 'in_maintenance')->json('data');
        $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/assign", ['employee_id' => $this->employeeRecord->public_id, 'version' => $current['version']])->assertStatus(422);

        $this->apiAs($this->admin, 'PATCH', "/api/v1/assets/maintenance/{$record['id']}", ['status' => 'scheduled', 'version' => $record['version']])->assertStatus(422);
        $done = $this->apiAs($this->admin, 'PATCH', "/api/v1/assets/maintenance/{$record['id']}", ['status' => 'completed', 'version' => $record['version']])->assertOk()->json('data');
        $this->assertNotNull($done['completed_on']);
        $this->apiAs($this->admin, 'PATCH', "/api/v1/assets/maintenance/{$record['id']}", ['vendor' => 'Other', 'version' => $done['version']])->assertStatus(422);
        $current = $this->apiAs($this->admin, 'GET', "/api/v1/assets/{$asset['id']}")->assertJsonPath('data.status', 'available')->json('data');
        $this->assertCount(1, $this->apiAs($this->admin, 'GET', "/api/v1/assets/{$asset['id']}/history")->json('data.maintenance'));

        $assigned = $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/assign", ['employee_id' => $this->employeeRecord->public_id, 'version' => $current['version']])->assertOk()->json('data');
        $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/maintenance", ['kind' => 'service', 'description' => 'Clean', 'status' => 'in_progress'])->assertStatus(422);
        $scheduled = $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/maintenance", ['kind' => 'inspection', 'description' => 'Annual check'])->assertCreated()->json('data');
        $this->apiAs($this->admin, 'PATCH', "/api/v1/assets/maintenance/{$scheduled['id']}", ['status' => 'in_progress', 'version' => $scheduled['version']])->assertStatus(422);
        $returned = $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/return", ['condition' => 'good', 'version' => $assigned['version']])->assertOk()->json('data');

        $retired = $this->apiAs($this->admin, 'PATCH', "/api/v1/assets/{$asset['id']}", ['status' => 'retired', 'version' => $returned['version']])->assertOk()->json('data');
        $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/maintenance", ['kind' => 'repair', 'description' => 'X'])->assertStatus(422);
        $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/assign", ['employee_id' => $this->employeeRecord->public_id, 'version' => $retired['version']])->assertStatus(422);
        $this->apiAs($this->admin, 'PATCH', "/api/v1/assets/{$asset['id']}", ['name' => 'Renamed', 'version' => $retired['version']])->assertStatus(422);
        $this->apiAs($this->admin, 'PATCH', "/api/v1/assets/{$asset['id']}", ['status' => 'available', 'version' => $retired['version']])->assertOk()->assertJsonPath('data.status', 'available');
    }

    public function test_only_asset_managers_reach_the_registry(): void
    {
        $asset = $this->asset();
        foreach ([$this->employee, $this->manager] as $user) {
            $this->apiAs($user, 'GET', '/api/v1/assets')->assertForbidden();
            $this->apiAs($user, 'GET', "/api/v1/assets/{$asset['id']}")->assertForbidden();
            $this->apiAs($user, 'GET', "/api/v1/assets/{$asset['id']}/history")->assertForbidden();
            $this->apiAs($user, 'POST', "/api/v1/assets/{$asset['id']}/assign", ['employee_id' => $this->employeeRecord->public_id, 'version' => 1])->assertForbidden();
            $this->apiAs($user, 'GET', '/api/v1/assets/categories')->assertForbidden();
            $this->apiAs($user, 'POST', '/api/v1/assets/categories', ['name' => 'Phones'])->assertForbidden();
            $this->apiAs($user, 'GET', '/api/v1/assets/mine')->assertOk()->assertJsonCount(0, 'data');
        }
        $auditor = $this->makeUser($this->tenant, 'auditor');
        $this->apiAs($auditor, 'GET', '/api/v1/assets/mine')->assertForbidden();
    }

    public function test_tenant_isolation_and_plan_entitlement(): void
    {
        $asset = $this->asset();
        $other = $this->makeTenant();
        $otherAdmin = $this->makeUser($other, 'hr-administrator');
        $otherEmployee = $this->makeEmployee($other, null, $otherAdmin);
        $this->apiAs($otherAdmin, 'GET', "/api/v1/assets/{$asset['id']}")->assertNotFound();
        $this->apiAs($otherAdmin, 'POST', "/api/v1/assets/{$asset['id']}/assign", ['employee_id' => $otherEmployee->public_id, 'version' => 1])->assertNotFound();
        $this->apiAs($otherAdmin, 'GET', '/api/v1/assets')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertCount(0, $this->apiAs($otherAdmin, 'GET', '/api/v1/assets/categories')->json('data'));
        $this->apiAs($this->admin, 'POST', "/api/v1/assets/{$asset['id']}/assign", ['employee_id' => $otherEmployee->public_id, 'version' => $asset['version']])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');

        $otherCategory = $this->apiAs($otherAdmin, 'POST', '/api/v1/assets/categories', ['name' => 'Laptops'])->assertCreated()->json('data');
        $this->apiAs($this->admin, 'POST', '/api/v1/assets', ['category_id' => $otherCategory['id'], 'asset_tag' => 'LT-009', 'name' => 'X'])->assertStatus(422)->assertJsonValidationErrors('category_id');

        Subscription::forTenant($this->tenant)->forceFill(['plan_id' => Plan::query()->where('code', 'starter')->value('id')])->saveQuietly();
        $this->apiAs($this->admin, 'GET', '/api/v1/assets')->assertStatus(402);
    }
}
