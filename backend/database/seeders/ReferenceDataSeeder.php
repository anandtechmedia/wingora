<?php

namespace Database\Seeders;

use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Idempotent reference data for Wingora's supported markets (US, AE, CA, MX). Safe to re-run in every environment. */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $this->upsert('currencies', 'code', [
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'minor_units' => 2],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'minor_units' => 2],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'CA$', 'minor_units' => 2],
            ['code' => 'MXN', 'name' => 'Mexican Peso', 'symbol' => 'MX$', 'minor_units' => 2],
        ], $now);

        $this->upsert('languages', 'code', [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'status' => 'active'],
            ['code' => 'es', 'name' => 'Spanish', 'native_name' => 'Español', 'direction' => 'ltr', 'status' => 'active'],
            ['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'direction' => 'rtl', 'status' => 'planned'],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'direction' => 'ltr', 'status' => 'planned'],
        ], $now, preserve: ['status']);

        $this->upsert('countries', 'code', [
            ['code' => 'US', 'iso3' => 'USA', 'name' => 'United States', 'default_currency_code' => 'USD', 'default_language_code' => 'en', 'default_timezone' => 'America/New_York', 'subdivision_label' => 'State'],
            ['code' => 'AE', 'iso3' => 'ARE', 'name' => 'United Arab Emirates', 'default_currency_code' => 'AED', 'default_language_code' => 'ar', 'default_timezone' => 'Asia/Dubai', 'subdivision_label' => 'Emirate'],
            ['code' => 'CA', 'iso3' => 'CAN', 'name' => 'Canada', 'default_currency_code' => 'CAD', 'default_language_code' => 'en', 'default_timezone' => 'America/Toronto', 'subdivision_label' => 'Province/Territory'],
            ['code' => 'MX', 'iso3' => 'MEX', 'name' => 'Mexico', 'default_currency_code' => 'MXN', 'default_language_code' => 'es', 'default_timezone' => 'America/Mexico_City', 'subdivision_label' => 'State'],
        ], $now);

        $languages = ['US' => ['en' => true, 'es' => false], 'AE' => ['ar' => true, 'en' => false], 'CA' => ['en' => true, 'fr' => true], 'MX' => ['es' => true, 'en' => false]];
        foreach ($languages as $country => $codes) {
            foreach ($codes as $code => $official) {
                DB::table('country_languages')->updateOrInsert(['country_code' => $country, 'language_code' => $code], ['is_official' => $official]);
            }
        }

        $zones = [
            'US' => ['America/New_York', 'America/Detroit', 'America/Indiana/Indianapolis', 'America/Chicago', 'America/Denver', 'America/Boise', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'America/Adak', 'Pacific/Honolulu'],
            'AE' => ['Asia/Dubai'],
            'CA' => ['America/St_Johns', 'America/Halifax', 'America/Moncton', 'America/Toronto', 'America/Winnipeg', 'America/Regina', 'America/Edmonton', 'America/Vancouver', 'America/Whitehorse', 'America/Iqaluit'],
            'MX' => ['America/Mexico_City', 'America/Cancun', 'America/Merida', 'America/Monterrey', 'America/Matamoros', 'America/Chihuahua', 'America/Ciudad_Juarez', 'America/Mazatlan', 'America/Hermosillo', 'America/Tijuana', 'America/Bahia_Banderas'],
        ];
        $valid = DateTimeZone::listIdentifiers();
        foreach ($zones as $country => $list) {
            foreach ($list as $zone) {
                in_array($zone, $valid, true) ?: throw new RuntimeException("Unknown IANA time zone {$zone}");
                DB::table('country_timezones')->updateOrInsert(['country_code' => $country, 'timezone' => $zone], []);
            }
        }

        foreach ($this->subdivisions() as $country => [$type, $items]) {
            foreach ($items as $code => $name) {
                [$name, $kind] = is_array($name) ? $name : [$name, $type];
                DB::table('subdivisions')->updateOrInsert(['code' => "{$country}-{$code}"], ['country_code' => $country, 'name' => $name, 'type' => $kind]);
            }
        }

        // Patterns use CLDR/ICU notation; reviewed defaults, overridable per user in the clients.
        $this->upsert('locales', 'code', [
            ['code' => 'en-US', 'language_code' => 'en', 'country_code' => 'US', 'date_format' => 'MM/dd/yyyy', 'time_format' => 'h:mm a', 'decimal_separator' => '.', 'group_separator' => ',', 'first_day_of_week' => 0],
            ['code' => 'es-US', 'language_code' => 'es', 'country_code' => 'US', 'date_format' => 'MM/dd/yyyy', 'time_format' => 'h:mm a', 'decimal_separator' => '.', 'group_separator' => ',', 'first_day_of_week' => 0],
            ['code' => 'en-CA', 'language_code' => 'en', 'country_code' => 'CA', 'date_format' => 'yyyy-MM-dd', 'time_format' => 'h:mm a', 'decimal_separator' => '.', 'group_separator' => ',', 'first_day_of_week' => 0],
            ['code' => 'fr-CA', 'language_code' => 'fr', 'country_code' => 'CA', 'date_format' => 'yyyy-MM-dd', 'time_format' => "HH 'h' mm", 'decimal_separator' => ',', 'group_separator' => "\u{00A0}", 'first_day_of_week' => 0],
            ['code' => 'es-MX', 'language_code' => 'es', 'country_code' => 'MX', 'date_format' => 'dd/MM/yyyy', 'time_format' => 'HH:mm', 'decimal_separator' => '.', 'group_separator' => ',', 'first_day_of_week' => 0],
            ['code' => 'en-MX', 'language_code' => 'en', 'country_code' => 'MX', 'date_format' => 'dd/MM/yyyy', 'time_format' => 'h:mm a', 'decimal_separator' => '.', 'group_separator' => ',', 'first_day_of_week' => 0],
            ['code' => 'en-AE', 'language_code' => 'en', 'country_code' => 'AE', 'date_format' => 'dd/MM/yyyy', 'time_format' => 'h:mm a', 'decimal_separator' => '.', 'group_separator' => ',', 'first_day_of_week' => 1],
            ['code' => 'ar-AE', 'language_code' => 'ar', 'country_code' => 'AE', 'date_format' => 'dd/MM/yyyy', 'time_format' => 'h:mm a', 'decimal_separator' => '.', 'group_separator' => ',', 'first_day_of_week' => 1],
        ], $now);
    }

    /** @param list<string> $preserve columns not overwritten on re-run (e.g. a language activated later) */
    private function upsert(string $table, string $key, array $rows, $now, array $preserve = []): void
    {
        foreach ($rows as $row) {
            $exists = DB::table($table)->where($key, $row[$key])->exists();
            $values = $exists ? array_diff_key($row, array_flip($preserve)) + ['updated_at' => $now] : $row + ['created_at' => $now, 'updated_at' => $now];
            DB::table($table)->updateOrInsert([$key => $row[$key]], $values);
        }
    }

    /** ISO 3166-2 subdivisions. */
    private function subdivisions(): array
    {
        return [
            'US' => ['state', [
                'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
                'CT' => 'Connecticut', 'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
                'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
                'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
                'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
                'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
                'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
                'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
                'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => ['District of Columbia', 'district'],
            ]],
            'CA' => ['province', [
                'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador',
                'NS' => 'Nova Scotia', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec', 'SK' => 'Saskatchewan',
                'NT' => ['Northwest Territories', 'territory'], 'NU' => ['Nunavut', 'territory'], 'YT' => ['Yukon', 'territory'],
            ]],
            'MX' => ['state', [
                'AGU' => 'Aguascalientes', 'BCN' => 'Baja California', 'BCS' => 'Baja California Sur', 'CAM' => 'Campeche', 'CHP' => 'Chiapas',
                'CHH' => 'Chihuahua', 'CMX' => ['Ciudad de México', 'federal_entity'], 'COA' => 'Coahuila', 'COL' => 'Colima', 'DUR' => 'Durango',
                'GUA' => 'Guanajuato', 'GRO' => 'Guerrero', 'HID' => 'Hidalgo', 'JAL' => 'Jalisco', 'MEX' => 'México', 'MIC' => 'Michoacán',
                'MOR' => 'Morelos', 'NAY' => 'Nayarit', 'NLE' => 'Nuevo León', 'OAX' => 'Oaxaca', 'PUE' => 'Puebla', 'QUE' => 'Querétaro',
                'ROO' => 'Quintana Roo', 'SLP' => 'San Luis Potosí', 'SIN' => 'Sinaloa', 'SON' => 'Sonora', 'TAB' => 'Tabasco',
                'TAM' => 'Tamaulipas', 'TLA' => 'Tlaxcala', 'VER' => 'Veracruz', 'YUC' => 'Yucatán', 'ZAC' => 'Zacatecas',
            ]],
            'AE' => ['emirate', [
                'AZ' => 'Abu Dhabi', 'AJ' => 'Ajman', 'FU' => 'Fujairah', 'SH' => 'Sharjah', 'DU' => 'Dubai', 'RK' => 'Ras Al Khaimah', 'UQ' => 'Umm Al Quwain',
            ]],
        ];
    }
}
