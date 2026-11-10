<?php

namespace Database\Seeders;

use App\Country\RuleCategory;
use App\Models\RuleSet;
use App\Models\RuleVersion;
use App\Models\RuleVersionEvent;
use Illuminate\Database\Seeder;

/**
 * Starting templates seeded as DRAFTS only. They are placeholders that must be reviewed against current law
 * by a qualified person and approved through the maker-checker workflow before any module relies on them.
 * Entitlements are intentionally null and no statutory calculations are defined.
 */
class CountryRuleTemplateSeeder extends Seeder
{
    private const NOTE = 'Seeded placeholder - requires legal review before approval.';

    public function run(): void
    {
        $t = fn (array $pairs) => array_map(fn ($k, $n) => ['key' => $k, 'name' => $n], array_keys($pairs), $pairs);

        $templates = [
            'US' => [
                RuleCategory::EmploymentTypes->value => ['types' => $t(['full_time' => 'Full-time', 'part_time' => 'Part-time', 'temporary' => 'Temporary', 'seasonal' => 'Seasonal', 'intern' => 'Intern'])],
                RuleCategory::Leave->value => ['leave_types' => $this->leave(['sick' => 'Sick leave (state/local rules vary)', 'family_medical' => 'Family and medical leave', 'vacation' => 'Vacation (company policy)'])],
                RuleCategory::Payroll->value => ['pay_frequencies' => ['weekly', 'biweekly', 'semimonthly', 'monthly'], 'statutory_items' => $t(['federal_income_tax' => 'Federal income tax withholding', 'fica' => 'Social Security and Medicare (FICA)', 'futa' => 'Federal unemployment (FUTA)', 'state_withholding' => 'State withholding (varies by state)'])],
                RuleCategory::Compliance->value => ['requirements' => $t(['form_i9' => 'Employment eligibility verification (Form I-9)', 'form_w4' => 'Federal withholding certificate (Form W-4)'])],
            ],
            'CA' => [
                RuleCategory::EmploymentTypes->value => ['types' => $t(['full_time' => 'Full-time', 'part_time' => 'Part-time', 'term' => 'Term / fixed-term', 'casual' => 'Casual', 'seasonal' => 'Seasonal'])],
                RuleCategory::Leave->value => ['leave_types' => $this->leave(['vacation' => 'Vacation (provincial rules vary)', 'sick' => 'Sick leave (provincial rules vary)', 'parental' => 'Parental leave'])],
                RuleCategory::Payroll->value => ['pay_frequencies' => ['weekly', 'biweekly', 'semimonthly', 'monthly'], 'statutory_items' => $t(['cpp' => 'Canada/Quebec Pension Plan', 'ei' => 'Employment Insurance', 'income_tax' => 'Federal and provincial income tax'])],
                RuleCategory::Compliance->value => ['requirements' => $t(['sin' => 'Social Insurance Number on file', 'td1' => 'Personal tax credits returns (TD1)'])],
            ],
            'MX' => [
                RuleCategory::EmploymentTypes->value => ['types' => $t(['indefinite' => 'Tiempo indeterminado', 'fixed_term' => 'Tiempo determinado', 'specific_work' => 'Obra determinada', 'seasonal' => 'Temporada', 'trial_period' => 'Periodo de prueba', 'initial_training' => 'Capacitación inicial'])],
                RuleCategory::Leave->value => ['leave_types' => $this->leave(['vacation' => 'Vacaciones', 'maternity' => 'Incapacidad por maternidad', 'paternity' => 'Permiso de paternidad'])],
                RuleCategory::Payroll->value => ['pay_frequencies' => ['weekly', 'semimonthly', 'monthly'], 'statutory_items' => $t(['isr' => 'ISR withholding', 'imss' => 'IMSS contributions', 'infonavit' => 'INFONAVIT contributions', 'cfdi_nomina' => 'CFDI payroll stamping'])],
                RuleCategory::Compliance->value => ['requirements' => $t(['rfc' => 'RFC on file', 'curp' => 'CURP on file', 'nss' => 'IMSS social security number (NSS)'])],
            ],
            'AE' => [
                RuleCategory::EmploymentTypes->value => ['types' => $t(['full_time' => 'Full-time', 'part_time' => 'Part-time', 'temporary' => 'Temporary', 'flexible' => 'Flexible'])],
                RuleCategory::Leave->value => ['leave_types' => $this->leave(['annual' => 'Annual leave', 'sick' => 'Sick leave', 'maternity' => 'Maternity leave'])],
                RuleCategory::Payroll->value => ['pay_frequencies' => ['monthly'], 'statutory_items' => $t(['wps' => 'Wage Protection System (WPS) file', 'pension_nationals' => 'Pension contributions for UAE/GCC nationals', 'gratuity' => 'End-of-service gratuity accrual'])],
                RuleCategory::Compliance->value => ['requirements' => $t(['emirates_id' => 'Emirates ID on file', 'work_permit' => 'Work permit / residence visa validity'])],
            ],
        ];

        foreach ($templates as $country => $categories) {
            foreach ($categories as $category => $payload) {
                $set = RuleSet::query()->firstOrNew(['country_code' => $country, 'subdivision_code' => null, 'category' => $category, 'key' => 'national-baseline']);
                if (! $set->exists) {
                    $set->fill(['name' => "{$country} ".str_replace('_', ' ', $category).' (national baseline)', 'description' => self::NOTE])->save();
                }
                if ($set->versions()->exists()) {
                    continue;
                }
                $version = new RuleVersion(['effective_from' => '2027-01-01', 'payload' => $payload, 'change_summary' => self::NOTE]);
                $version->forceFill(['rule_set_id' => $set->id, 'version' => 1])->save();
                RuleVersionEvent::log($version, 'created', null, RuleVersion::DRAFT, null, self::NOTE);
            }
        }
    }

    private function leave(array $pairs): array
    {
        return array_map(fn ($k, $n) => ['key' => $k, 'name' => $n, 'paid' => true, 'unit' => 'days', 'entitlement' => null, 'notes' => self::NOTE], array_keys($pairs), $pairs);
    }
}
