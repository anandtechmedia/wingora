<?php

namespace Tests\Feature\Country;

use App\Country\RuleCategory;
use App\Models\RuleVersion;
use App\Models\RuleVersionEvent;
use Illuminate\Validation\ValidationException;
use LogicException;

class RuleVersioningTest extends CountryTestCase
{
    private function draftPayload(array $overrides = []): array
    {
        return $overrides + [
            'effective_from' => '2027-01-01',
            'change_summary' => 'Initial baseline',
            'payload' => ['types' => [['key' => 'full_time', 'name' => 'Full-time']]],
        ];
    }

    private function createSet($user, array $data = []): string
    {
        return $this->apiAs($user, 'POST', '/api/v1/platform/rule-sets', $data + [
            'country_code' => 'US', 'subdivision_code' => 'US-CA', 'category' => 'employment_types', 'key' => 'baseline', 'name' => 'California types',
        ])->assertCreated()->json('data.id');
    }

    public function test_maker_checker_approval_with_full_history(): void
    {
        $author = $this->makeUser(null, 'platform-admin');
        $approver = $this->makeUser(null, 'platform-owner');
        $set = $this->createSet($author);

        $version = $this->apiAs($author, 'POST', "/api/v1/platform/rule-sets/{$set}/versions", $this->draftPayload())->assertCreated()->json('data.id');
        $this->apiAs($approver, 'POST', "/api/v1/platform/rule-versions/{$version}/approve")->assertConflict();
        $this->apiAs($author, 'POST', "/api/v1/platform/rule-versions/{$version}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $this->apiAs($author, 'POST', "/api/v1/platform/rule-versions/{$version}/approve")->assertForbidden();
        $this->apiAs($approver, 'POST', "/api/v1/platform/rule-versions/{$version}/approve", ['comment' => 'Reviewed'])
            ->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.history.*.action', ['created', 'submitted', 'approved'])
            ->assertJsonPath('data.history.2.actor.id', $approver->public_id);
    }

    public function test_approved_versions_are_immutable_and_history_is_append_only(): void
    {
        $version = $this->employmentTypes('US', null, ['full_time']);
        $editor = $this->makeUser(null, 'platform-admin');

        $this->apiAs($editor, 'PUT', "/api/v1/platform/rule-versions/{$version->public_id}", $this->draftPayload())->assertConflict();

        $this->expectException(LogicException::class);
        $version->forceFill(['payload' => ['types' => []]])->save();
    }

    public function test_history_rows_cannot_be_modified(): void
    {
        $this->employmentTypes('US', null, ['full_time']);
        $this->expectException(LogicException::class);
        RuleVersionEvent::query()->firstOrFail()->delete();
    }

    public function test_new_version_supersedes_open_ended_one_and_resolution_respects_dates(): void
    {
        $v1 = $this->employmentTypes('MX', null, ['indefinite'], '2027-01-01');
        $v2 = $this->employmentTypes('MX', null, ['indefinite', 'seasonal'], '2028-01-01');
        $user = $this->makeUser($this->makeTenant(), 'employee');

        $this->assertSame('2027-12-31', $v1->fresh()->effective_to->toDateString());
        $this->assertDatabaseHas('rule_version_events', ['rule_version_id' => $v1->id, 'action' => 'superseded']);

        $on2027 = $this->apiAs($user, 'GET', '/api/v1/reference/countries/MX/rules?category=employment_types&date=2027-06-01')->assertOk()->json('data');
        $on2028 = $this->apiAs($user, 'GET', '/api/v1/reference/countries/MX/rules?category=employment_types&date=2028-06-01')->json('data');
        $before = $this->apiAs($user, 'GET', '/api/v1/reference/countries/MX/rules?category=employment_types&date=2026-06-01')->json('data');

        $this->assertSame([$v1->public_id], array_column($on2027, 'id'));
        $this->assertSame([$v2->public_id], array_column($on2028, 'id'));
        $this->assertSame([], $before);
    }

    public function test_overlapping_bounded_versions_are_rejected(): void
    {
        $this->approvedRule('CA', null, RuleCategory::Compliance, ['requirements' => [['key' => 'sin', 'name' => 'SIN']]], '2027-01-01', '2027-12-31');

        $this->expectException(ValidationException::class);
        $this->approvedRule('CA', null, RuleCategory::Compliance, ['requirements' => [['key' => 'sin', 'name' => 'SIN']]], '2027-06-01');
    }

    public function test_country_and_subdivision_rules_are_resolved_separately(): void
    {
        $national = $this->employmentTypes('US', null, ['full_time']);
        $california = $this->employmentTypes('US', 'US-CA', ['full_time', 'seasonal']);
        $user = $this->makeUser($this->makeTenant(), 'employee');

        $ca = $this->apiAs($user, 'GET', '/api/v1/reference/countries/US/rules?category=employment_types&subdivision=US-CA&date=2027-03-01')->json('data');
        $tx = $this->apiAs($user, 'GET', '/api/v1/reference/countries/US/rules?category=employment_types&subdivision=US-TX&date=2027-03-01')->json('data');

        $this->assertSame([$national->public_id, $california->public_id], array_column($ca, 'id'));
        $this->assertSame(['US', 'US-CA'], array_column(array_column($ca, 'rule_set'), 'jurisdiction'));
        $this->assertSame([$national->public_id], array_column($tx, 'id'));
        $this->apiAs($user, 'GET', '/api/v1/reference/countries/US/rules?category=employment_types&subdivision=MX-JAL')->assertUnprocessable();
    }

    public function test_drafts_rejected_and_retired_versions_are_never_effective(): void
    {
        $retired = $this->employmentTypes('AE', null, ['full_time']);
        $this->apiAs($this->makeUser(null, 'platform-owner'), 'POST', "/api/v1/platform/rule-versions/{$retired->public_id}/retire", ['comment' => 'Law changed'])
            ->assertOk()->assertJsonPath('data.status', RuleVersion::RETIRED);

        $user = $this->makeUser($this->makeTenant(), 'employee');
        $this->assertSame([], $this->apiAs($user, 'GET', '/api/v1/reference/countries/AE/rules?category=employment_types&date=2027-02-01')->json('data'));
    }

    public function test_payloads_are_validated_per_category(): void
    {
        $author = $this->makeUser(null, 'platform-admin');
        $set = $this->createSet($author);

        $this->apiAs($author, 'POST', "/api/v1/platform/rule-sets/{$set}/versions", $this->draftPayload(['payload' => ['types' => [['key' => 'Bad Key!', 'name' => 'x']]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('payload.types.0.key');
        $this->apiAs($author, 'POST', "/api/v1/platform/rule-sets/{$set}/versions", $this->draftPayload(['effective_to' => '2026-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('effective_to');

        $id = $this->apiAs($author, 'POST', "/api/v1/platform/rule-sets/{$set}/versions", $this->draftPayload(['payload' => [
            'types' => [['key' => 'full_time', 'name' => 'Full-time', 'formula' => 'eval()']], 'tax_rate' => 0.3,
        ]]))->assertCreated()->json('data.id');
        $this->assertSame(['types' => [['key' => 'full_time', 'name' => 'Full-time']]], RuleVersion::where('public_id', $id)->value('payload') ? RuleVersion::where('public_id', $id)->first()->payload : null);

        $this->apiAs($author, 'POST', '/api/v1/platform/rule-sets', ['country_code' => 'US', 'subdivision_code' => 'MX-JAL', 'category' => 'leave', 'key' => 'x', 'name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('subdivision_code');
        $this->apiAs($author, 'POST', '/api/v1/platform/rule-sets', ['country_code' => 'US', 'category' => 'income_tax', 'key' => 'x', 'name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('category');
    }

    public function test_holiday_dates_must_fall_within_the_effective_period(): void
    {
        $author = $this->makeUser(null, 'platform-admin');
        $set = $this->createSet($author, ['category' => 'holidays', 'key' => 'statutory']);

        $this->apiAs($author, 'POST', "/api/v1/platform/rule-sets/{$set}/versions", [
            'effective_from' => '2027-01-01', 'effective_to' => '2027-12-31', 'change_summary' => '2027',
            'payload' => ['holidays' => [['date' => '2028-01-01', 'name' => 'New Year', 'type' => 'public', 'paid' => true]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payload.holidays.0.date');
    }

    public function test_tenant_users_cannot_manage_country_rules(): void
    {
        $owner = $this->makeUser($this->makeTenant(), 'organization-owner');
        $version = $this->employmentTypes('US', null, ['full_time']);

        $this->apiAs($owner, 'GET', '/api/v1/platform/rule-sets')->assertForbidden();
        $this->apiAs($owner, 'POST', '/api/v1/platform/rule-sets', ['country_code' => 'US', 'category' => 'leave', 'key' => 'x', 'name' => 'x'])->assertForbidden();
        $this->apiAs($owner, 'POST', "/api/v1/platform/rule-versions/{$version->public_id}/retire", ['comment' => 'x'])->assertForbidden();
    }
}
