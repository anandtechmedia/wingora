# Country configuration foundation

Status: implemented in `backend/` for USA, UAE, Canada and Mexico. No statutory tax or payroll calculations are implemented. HR modules read configuration from here instead of hard-coding country behaviour.

## Principles

- **Configuration, not code.** Country behaviour lives in data (reference tables and versioned rules), validated per category.
- **No single national rule set.** Rules are attached to a *jurisdiction*: a country (`US`) or an ISO 3166-2 subdivision (`US-CA`, `CA-QC`, `MX-JAL`, `AE-DU`). Lookups return country-level and subdivision-level rules side by side, labelled with their jurisdiction. Nothing is merged into one national rule.
- **Effective-dated and approved.** Only `approved` versions whose effective range contains the date are ever returned.
- **Fail closed.** If no approved employment types exist for a location's jurisdiction on a date, the employee cannot be assigned there.
- **Tenant isolation.** Legal entities, work locations, holiday calendars and holidays are tenant-owned (global tenant scope plus composite foreign keys that include `tenant_id`).

## Data model

```
Global reference (seeded by ReferenceDataSeeder, read-only API)
  currencies         USD, AED, CAD, MXN
  languages          en, es (active)   ar (rtl), fr (planned)
  countries          US, AE, CA, MX   -> default currency, language, time zone
  country_languages  official / supported languages per country
  country_timezones  IANA zones valid per country
  subdivisions       51 US (50 + DC), 13 CA, 32 MX, 7 AE
  locales            en-US, es-US, en-CA, fr-CA, es-MX, en-MX, en-AE, ar-AE (date/time patterns, separators, first weekday)

Platform rules (managed by platform staff)
  rule_sets            jurisdiction + category + key
  rule_versions        version, status, effective_from/to, payload, change_summary, legal_reference
  rule_version_events  append-only approval history

Tenant-owned
  legal_entities     country, subdivision, currency, language, encrypted registration/tax ids
  work_locations     belongs to a legal entity; same country; subdivision + time zone + optional holiday calendar
  holiday_calendars  jurisdiction; include_statutory flag
  holidays           company holidays
  employees          + legal_entity_id, work_location_id, employment_type
```

Database guarantees:
- `work_locations (legal_entity_id, tenant_id)` references `legal_entities (id, tenant_id)`.
- `employees (work_location_id, legal_entity_id)` references `work_locations (id, legal_entity_id)`, so an employee's location always belongs to their entity.
- `employees (legal_entity_id, tenant_id)` references `legal_entities (id, tenant_id)`.

## Rule categories

| Category | Payload | Notes |
| --- | --- | --- |
| `employment_types` | `types[]: key, name, description?` | Validates `employees.employment_type` |
| `leave` | `leave_types[]: key, name, paid, unit (days/hours), entitlement (nullable), notes?` | Baseline for the future Time Off module |
| `payroll` | `pay_frequencies[]`, `statutory_items[]: key, name, notes?` | Placeholders only, with no rates or formulas |
| `compliance` | `requirements[]: key, name, description?, reference?` | Basis for compliance alerts |
| `holidays` | `holidays[]: date, name, type (public/optional/observance), paid` | Dates must fall inside the effective period |

Unknown payload keys are dropped on validation. For example, a `formula` or `tax_rate` sent by a client is never stored.

## Versioning and approval

```
draft --submit--> pending_approval --approve--> approved --retire--> retired
                                  \--reject---> rejected
```

- **Maker-checker:** the approver cannot be the creator, the last editor or the submitter (`RuleVersionPolicy::approve`). Approve/reject/retire require `platform.country-rules.approve`; authoring requires `platform.country-rules.manage`. Both permissions are held by Platform Owner and Platform Administrator, so approval needs two different people.
- Content (payload, dates, summary, reference) is immutable once a version leaves draft. Corrections are made as a new version.
- **Approval effects:**
  - If an open-ended approved version starts earlier, it is end-dated the day before the new version starts. This is recorded as `superseded`.
  - Any other overlap is rejected.
- Every action writes a `rule_version_events` row with the actor, comment and request id. Rows cannot be updated or deleted.

`CountryRuleTemplateSeeder` seeds **draft** national baselines for the four countries (employment types, leave types with null entitlements, payroll placeholders and compliance items). They are marked "requires legal review" and must be approved by a qualified reviewer before any module relies on them. No holiday dates are seeded.

## Localization

- `SetLocale` picks the response language from, in order:
  1. The user's saved locale (`PATCH /api/v1/auth/preferences`).
  2. `Accept-Language`, restricted to locales of **active** languages.
  3. English.
- The chosen locale is returned in `Content-Language`.
- Translations live in `lang/{en,es}.json`; `lang/{ar,fr}.json` exist empty. To activate Arabic or French:
  1. Translate the keys.
  2. Set `languages.status = active`.
  3. For Arabic, have clients honour `direction = rtl` from `/reference/languages`.
- Date and number formats come from `/reference/locales` (CLDR/ICU patterns). Clients format values, and the API returns ISO 8601 dates and plain decimals.

## API

| Method & path | Who |
| --- | --- |
| `GET /api/v1/reference/{countries, countries/{code}, currencies, languages, locales}` | Any authenticated user |
| `GET /api/v1/reference/countries/{code}/rules?category=&subdivision=&date=` | Any authenticated user (approved rules only) |
| `GET/POST /api/v1/platform/rule-sets`, `GET /rule-sets/{id}`, `POST /rule-sets/{id}/versions` | Platform, `country-rules.manage` |
| `GET/PUT /api/v1/platform/rule-versions/{id}`, `POST .../submit` | Platform, `country-rules.manage` |
| `POST /api/v1/platform/rule-versions/{id}/{approve, reject, retire}` | Platform, `country-rules.approve`, maker-checker |
| `GET/POST/PATCH /api/v1/legal-entities[/{id}]` | View: `organization.view`; manage: `organization.entities.manage`; identifiers: manage or `payroll.view` |
| `GET/POST/PATCH /api/v1/work-locations[/{id}]` | Same as legal entities |
| `GET /api/v1/work-locations/{id}/rules?category=&date=` | `organization.view` |
| `GET /api/v1/work-locations/{id}/holidays?year=` | `organization.view`; returns statutory (approved) and company holidays |
| `GET/POST/PATCH /api/v1/holiday-calendars[/{id}]`, `POST .../holidays`, `DELETE /holidays/{id}` | View: `organization.view`; manage: `holidays.manage` |
| `PUT /api/v1/employees/{id}/assignment` | `employees.manage` |

## Known gaps

- No approved rule content ships. Legal review and approval are required per jurisdiction.
- Laravel's validation messages are not yet translated to Spanish (only API problem titles are).
- Reference data changes go through seeders and code review; there is no admin UI.
- Existing employee rows may have no entity or location (the columns are nullable for additive migration). The assignment API enforces the rule for all new assignments.
- Municipal/city-level rules (e.g. US city ordinances) and UAE free-zone regimes would need an additional jurisdiction level.
