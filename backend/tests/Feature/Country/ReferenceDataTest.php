<?php

namespace Tests\Feature\Country;

use App\Models\Subdivision;

class ReferenceDataTest extends CountryTestCase
{
    public function test_supported_markets_currencies_and_subdivisions_are_seeded(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'employee');

        $countries = $this->apiAs($user, 'GET', '/api/v1/reference/countries')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['US', 'AE', 'CA', 'MX'], array_column($countries, 'code'));
        $this->assertEqualsCanonicalizing(['USD', 'AED', 'CAD', 'MXN'], array_column($countries, 'default_currency_code'));

        $this->assertSame(51, Subdivision::where('country_code', 'US')->count());
        $this->assertSame(13, Subdivision::where('country_code', 'CA')->count());
        $this->assertSame(32, Subdivision::where('country_code', 'MX')->count());
        $this->assertSame(7, Subdivision::where('country_code', 'AE')->count());

        $ca = $this->apiAs($user, 'GET', '/api/v1/reference/countries/CA')->assertOk()->json('data');
        $this->assertContains('America/Toronto', $ca['timezones']);
        $this->assertContains('CA-QC', array_column($ca['subdivisions'], 'code'));
        $this->assertEqualsCanonicalizing(['en', 'fr'], array_column(array_filter($ca['languages'], fn ($l) => $l['official']), 'code'));

        $this->apiAs($user, 'GET', '/api/v1/reference/countries/FR')->assertNotFound();
        $this->assertEqualsCanonicalizing(['USD', 'AED', 'CAD', 'MXN'], array_column($this->apiAs($user, 'GET', '/api/v1/reference/currencies')->json('data'), 'code'));
    }

    public function test_english_and_spanish_are_active_and_arabic_french_are_planned(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'employee');
        $languages = collect($this->apiAs($user, 'GET', '/api/v1/reference/languages')->json('data'))->keyBy('code');

        $this->assertSame('active', $languages['en']['status']);
        $this->assertSame('active', $languages['es']['status']);
        $this->assertSame('planned', $languages['ar']['status']);
        $this->assertSame('rtl', $languages['ar']['direction']);
        $this->assertSame('planned', $languages['fr']['status']);

        $locales = collect($this->apiAs($user, 'GET', '/api/v1/reference/locales')->json('data'))->keyBy('code');
        $this->assertSame('dd/MM/yyyy', $locales['es-MX']['date_format']);
        $this->assertSame(',', $locales['fr-CA']['decimal_separator']);
        $this->assertFalse($locales['ar-AE']['active']);
    }

    public function test_responses_are_localized_to_active_languages_only(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'employee');

        $this->withHeader('Accept-Language', 'es-MX,es;q=0.9')->apiAs($user, 'GET', '/api/v1/employees/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertNotFound()->assertHeader('Content-Language', 'es-MX')->assertJsonPath('title', 'No encontrado');

        $this->withHeader('Accept-Language', 'ar-AE')->apiAs($user, 'GET', '/api/v1/employees/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertNotFound()->assertHeader('Content-Language', 'en')->assertJsonPath('title', 'Not found');
    }

    public function test_users_can_save_an_active_locale_but_not_a_planned_one(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'employee');

        $this->apiAs($user, 'PATCH', '/api/v1/auth/preferences', ['locale' => 'ar-AE'])->assertUnprocessable();
        $this->apiAs($user, 'PATCH', '/api/v1/auth/preferences', ['locale' => 'es-US'])->assertOk();
        $this->flushHeaders();
        $this->apiAs($user->fresh(), 'GET', '/api/v1/auth/me')->assertHeader('Content-Language', 'es-US');
    }

    public function test_spanish_translations_cover_every_english_key(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true);
        $es = json_decode(file_get_contents(lang_path('es.json')), true);

        $this->assertSame([], array_diff(array_keys($en), array_keys($es)));
    }

    public function test_reference_data_requires_authentication(): void
    {
        $this->getJson('/api/v1/reference/countries')->assertUnauthorized();
    }
}
