<?php

namespace Tests\Feature\Country;

use App\Country\RuleCategory;
use App\Country\RuleWorkflow;
use App\Models\LegalEntity;
use App\Models\RuleSet;
use App\Models\RuleVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Tenancy;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

abstract class CountryTestCase extends SecurityTestCase
{
    protected bool $seed = true;

    protected string $seeder = ReferenceDataSeeder::class;

    /** `$seed` only applies when this process migrates; seed here too when another test class migrated first. */
    protected function afterRefreshingDatabase(): void
    {
        if (! DB::table('countries')->exists()) {
            $this->seed(ReferenceDataSeeder::class);
        }
    }

    /** Creates and approves a rule version through the real workflow (author and approver differ). */
    protected function approvedRule(string $country, ?string $subdivision, RuleCategory $category, array $payload, string $from = '2027-01-01', ?string $to = null, string $key = 'baseline'): RuleVersion
    {
        $author = $this->makeUser(null, 'platform-admin');
        $approver = $this->makeUser(null, 'platform-owner');
        $set = RuleSet::query()->firstOrCreate(
            ['country_code' => $country, 'subdivision_code' => $subdivision, 'category' => $category->value, 'key' => $key],
            ['name' => "{$country} {$category->value}"],
        );
        $workflow = app(RuleWorkflow::class);
        $version = $workflow->createDraft($set, ['effective_from' => $from, 'effective_to' => $to, 'payload' => $payload, 'change_summary' => 'test', 'legal_reference' => null], $author);
        $workflow->submit($version, $author, null);

        return $workflow->approve($version, $approver, null);
    }

    protected function employmentTypes(string $country, ?string $subdivision, array $keys, string $from = '2027-01-01'): RuleVersion
    {
        return $this->approvedRule($country, $subdivision, RuleCategory::EmploymentTypes,
            ['types' => array_map(fn ($k) => ['key' => $k, 'name' => ucfirst($k)], $keys)], $from, key: 'types-'.($subdivision ?? 'national'));
    }

    protected function entity(Tenant $tenant, string $country = 'US', array $attributes = []): LegalEntity
    {
        $currency = ['US' => 'USD', 'CA' => 'CAD', 'MX' => 'MXN', 'AE' => 'AED'][$country];
        $language = ['US' => 'en', 'CA' => 'en', 'MX' => 'es', 'AE' => 'ar'][$country];

        return Tenancy::run($tenant, fn () => LegalEntity::create($attributes + [
            'name' => "{$country} Co", 'legal_name' => "{$country} Co ".fake()->unique()->numerify('####'),
            'country_code' => $country, 'currency_code' => $currency, 'default_language_code' => $language,
        ]));
    }

    protected function location(LegalEntity $entity, string $subdivision, string $timezone): WorkLocation
    {
        return Tenancy::run($entity->tenant, function () use ($entity, $subdivision, $timezone) {
            $location = new WorkLocation(['name' => "{$subdivision} office", 'country_code' => $entity->country_code, 'subdivision_code' => $subdivision, 'timezone' => $timezone]);
            $location->forceFill(['legal_entity_id' => $entity->id])->save();

            return $location;
        });
    }

    protected function hrAdmin(Tenant $tenant): User
    {
        return $this->makeUser($tenant, 'hr-administrator');
    }
}
