<?php

namespace Tests\Feature\Country;

use App\Country\RuleCategory;
use App\Models\LegalEntity;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrganizationStructureTest extends CountryTestCase
{
    public function test_tenant_can_have_entities_and_offices_in_several_countries(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->hrAdmin($tenant);

        $mx = $this->apiAs($admin, 'POST', '/api/v1/legal-entities', [
            'name' => 'Wingora MX', 'legal_name' => 'Wingora México S. de R.L. de C.V.', 'country_code' => 'MX',
            'subdivision_code' => 'MX-JAL', 'currency_code' => 'MXN', 'default_language_code' => 'es', 'tax_identifier' => 'WME123456AB1',
        ])->assertCreated()->assertJsonPath('data.tax_identifier', 'WME123456AB1')->json('data.id');
        $ae = $this->apiAs($admin, 'POST', '/api/v1/legal-entities', [
            'name' => 'Wingora AE', 'legal_name' => 'Wingora FZ-LLC', 'country_code' => 'AE', 'currency_code' => 'AED', 'default_language_code' => 'en',
        ])->assertCreated()->json('data.id');

        $this->apiAs($admin, 'POST', '/api/v1/work-locations', ['legal_entity_id' => $mx, 'name' => 'Guadalajara', 'subdivision_code' => 'MX-JAL', 'timezone' => 'America/Mexico_City'])
            ->assertCreated()->assertJsonPath('data.country_code', 'MX');
        $this->apiAs($admin, 'POST', '/api/v1/work-locations', ['legal_entity_id' => $ae, 'name' => 'Dubai', 'subdivision_code' => 'AE-DU', 'timezone' => 'Asia/Dubai'])
            ->assertCreated();

        $this->assertSame(2, $this->apiAs($admin, 'GET', '/api/v1/work-locations')->json('meta.total'));
        $raw = DB::table('legal_entities')->where('public_id', $mx)->value('tax_identifier');
        $this->assertStringNotContainsString('WME123456AB1', $raw);
    }

    public function test_jurisdiction_consistency_is_validated(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->hrAdmin($tenant);
        $us = $this->entity($tenant, 'US');

        $this->apiAs($admin, 'POST', '/api/v1/legal-entities', ['name' => 'x', 'legal_name' => 'x', 'country_code' => 'US', 'subdivision_code' => 'CA-ON', 'currency_code' => 'EUR', 'default_language_code' => 'fr'])
            ->assertUnprocessable()->assertJsonValidationErrors(['subdivision_code', 'currency_code', 'default_language_code']);
        $this->apiAs($admin, 'POST', '/api/v1/legal-entities', ['name' => 'x', 'legal_name' => 'x', 'country_code' => 'FR', 'currency_code' => 'USD', 'default_language_code' => 'en'])
            ->assertUnprocessable()->assertJsonValidationErrors('country_code');
        $this->apiAs($admin, 'POST', '/api/v1/work-locations', ['legal_entity_id' => $us->public_id, 'name' => 'Toronto', 'subdivision_code' => 'CA-ON', 'timezone' => 'America/Toronto'])
            ->assertUnprocessable()->assertJsonValidationErrors(['subdivision_code', 'timezone']);
        $this->apiAs($admin, 'POST', '/api/v1/work-locations', ['legal_entity_id' => $us->public_id, 'country_code' => 'CA', 'name' => 'x', 'subdivision_code' => 'US-NY', 'timezone' => 'America/New_York'])
            ->assertUnprocessable()->assertJsonValidationErrors('country_code');
        $this->apiAs($admin, 'PATCH', "/api/v1/legal-entities/{$us->public_id}", ['country_code' => 'MX'])
            ->assertUnprocessable()->assertJsonValidationErrors('country_code');
    }

    public function test_tenants_cannot_see_or_reference_each_others_entities_and_locations(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $entityA = $this->entity($a, 'CA');
        $locationA = $this->location($entityA, 'CA-ON', 'America/Toronto');
        $adminB = $this->hrAdmin($b);

        $this->apiAs($adminB, 'GET', "/api/v1/legal-entities/{$entityA->public_id}")->assertNotFound();
        $this->apiAs($adminB, 'GET', "/api/v1/work-locations/{$locationA->public_id}")->assertNotFound();
        $this->apiAs($adminB, 'PATCH', "/api/v1/legal-entities/{$entityA->public_id}", ['name' => 'pwned'])->assertNotFound();
        $this->apiAs($adminB, 'POST', '/api/v1/work-locations', ['legal_entity_id' => $entityA->public_id, 'name' => 'x', 'subdivision_code' => 'CA-ON', 'timezone' => 'America/Toronto'])
            ->assertUnprocessable()->assertJsonValidationErrors('legal_entity_id');
        $this->assertSame(0, $this->apiAs($adminB, 'GET', '/api/v1/legal-entities')->json('meta.total'));

        $employeeB = $this->makeEmployee($b);
        $this->employmentTypes('CA', null, ['full_time']);
        $this->apiAs($adminB, 'PUT', "/api/v1/employees/{$employeeB->public_id}/assignment", [
            'legal_entity_id' => $entityA->public_id, 'work_location_id' => $locationA->public_id, 'employment_type' => 'full_time', 'effective_date' => '2027-02-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('legal_entity_id');
    }

    public function test_database_rejects_cross_tenant_and_mismatched_links(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $entityA = $this->entity($a, 'US');
        $otherEntityA = $this->entity($a, 'MX');
        $locationA = $this->location($entityA, 'US-NY', 'America/New_York');

        $this->expectException(QueryException::class);
        Tenancy::run($a, fn () => $this->makeEmployee($a)->forceFill(['legal_entity_id' => $otherEntityA->id, 'work_location_id' => $locationA->id])->save());
    }

    public function test_database_rejects_location_for_another_tenants_entity(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $entityA = $this->entity($a, 'US');

        $this->expectException(QueryException::class);
        $this->location(LegalEntity::query()->withoutGlobalScopes()->find($entityA->id)->setRelation('tenant', $b), 'US-NY', 'America/New_York');
    }

    public function test_employee_assignment_requires_matching_entity_location_and_approved_type(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->hrAdmin($tenant);
        $us = $this->entity($tenant, 'US');
        $mx = $this->entity($tenant, 'MX');
        $california = $this->location($us, 'US-CA', 'America/Los_Angeles');
        $jalisco = $this->location($mx, 'MX-JAL', 'America/Mexico_City');
        $employee = $this->makeEmployee($tenant);
        $uri = "/api/v1/employees/{$employee->public_id}/assignment";
        $body = fn ($entity, $location, $type) => ['legal_entity_id' => $entity->public_id, 'work_location_id' => $location->public_id, 'employment_type' => $type, 'effective_date' => '2027-03-01'];

        $this->apiAs($admin, 'PUT', $uri, $body($us, $california, 'full_time'))->assertUnprocessable()->assertJsonValidationErrors('employment_type');

        $this->employmentTypes('US', null, ['full_time', 'part_time']);
        $this->employmentTypes('MX', null, ['indefinite', 'fixed_term']);

        $this->apiAs($admin, 'PUT', $uri, $body($us, $jalisco, 'full_time'))->assertUnprocessable()->assertJsonValidationErrors('work_location_id');
        $this->apiAs($admin, 'PUT', $uri, $body($us, $california, 'indefinite'))->assertUnprocessable()->assertJsonValidationErrors('employment_type');
        $this->apiAs($admin, 'PUT', $uri, $body($mx, $jalisco, 'indefinite'))->assertOk()
            ->assertJsonPath('data.legal_entity.country_code', 'MX')->assertJsonPath('data.work_location.subdivision_code', 'MX-JAL')
            ->assertJsonPath('data.employment_type', 'indefinite');
    }

    public function test_only_authorized_roles_manage_structure_and_see_identifiers(): void
    {
        $tenant = $this->makeTenant();
        $entity = $this->entity($tenant, 'US', ['tax_identifier' => '12-3456789']);
        $employee = $this->makeUser($tenant, 'employee');
        $payroll = $this->makeUser($tenant, 'payroll-administrator');
        $target = $this->makeEmployee($tenant);

        $this->apiAs($employee, 'GET', "/api/v1/legal-entities/{$entity->public_id}")->assertOk()->assertJsonMissingPath('data.tax_identifier');
        $this->apiAs($employee, 'POST', '/api/v1/legal-entities', ['name' => 'x'])->assertForbidden();
        $this->apiAs($employee, 'POST', '/api/v1/holiday-calendars', ['name' => 'x', 'country_code' => 'US'])->assertForbidden();
        $this->apiAs($employee, 'PUT', "/api/v1/employees/{$target->public_id}/assignment", [])->assertForbidden();
        $this->apiAs($payroll, 'GET', "/api/v1/legal-entities/{$entity->public_id}")->assertJsonPath('data.tax_identifier', '12-3456789');
        $this->apiAs($payroll, 'PATCH', "/api/v1/legal-entities/{$entity->public_id}", ['name' => 'x'])->assertForbidden();
    }

    public function test_location_holidays_combine_approved_statutory_and_company_holidays(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->hrAdmin($tenant);
        $entity = $this->entity($tenant, 'CA');
        $location = $this->location($entity, 'CA-QC', 'America/Toronto');
        $this->approvedRule('CA', null, RuleCategory::Holidays, ['holidays' => [['date' => '2027-07-01', 'name' => 'Canada Day', 'type' => 'public', 'paid' => true]]], '2027-01-01', '2027-12-31');
        $this->approvedRule('CA', 'CA-QC', RuleCategory::Holidays, ['holidays' => [['date' => '2027-06-24', 'name' => 'Fête nationale', 'type' => 'public', 'paid' => true]]], '2027-01-01', '2027-12-31');
        $this->approvedRule('CA', 'CA-ON', RuleCategory::Holidays, ['holidays' => [['date' => '2027-08-02', 'name' => 'Civic Holiday', 'type' => 'public', 'paid' => true]]], '2027-01-01', '2027-12-31');

        $calendar = $this->apiAs($admin, 'POST', '/api/v1/holiday-calendars', ['name' => 'Quebec', 'country_code' => 'CA', 'subdivision_code' => 'CA-QC'])->assertCreated()->json('data.id');
        $this->apiAs($admin, 'POST', "/api/v1/holiday-calendars/{$calendar}/holidays", ['date' => '2027-12-24', 'name' => 'Company day'])->assertCreated();
        $this->apiAs($admin, 'PATCH', "/api/v1/work-locations/{$location->public_id}", ['holiday_calendar_id' => $calendar])->assertOk();

        $holidays = $this->apiAs($admin, 'GET', "/api/v1/work-locations/{$location->public_id}/holidays?year=2027")->assertOk()->json('data');
        $this->assertSame(['2027-06-24', '2027-07-01', '2027-12-24'], array_column($holidays, 'date'));
        $this->assertSame(['statutory', 'statutory', 'company'], array_column($holidays, 'source'));

        $ontarioCalendar = $this->apiAs($admin, 'POST', '/api/v1/holiday-calendars', ['name' => 'Ontario', 'country_code' => 'CA', 'subdivision_code' => 'CA-ON'])->json('data.id');
        $this->apiAs($admin, 'PATCH', "/api/v1/work-locations/{$location->public_id}", ['holiday_calendar_id' => $ontarioCalendar])
            ->assertUnprocessable()->assertJsonValidationErrors('holiday_calendar_id');
    }
}
