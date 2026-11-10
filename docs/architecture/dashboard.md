# Organization dashboard API

`GET /api/v1/dashboard` returns aggregated, tenant-scoped figures for the web dashboard. It never returns employee lists for the client to count.

## Request

| Query | Rule |
| --- | --- |
| `country` | Optional ISO 3166-1 alpha-2 code from `countries`. Restricts to legal entities in that country. |
| `legal_entity` | Optional legal entity public id. Resolved through the tenant scope, so another tenant's id fails validation the same way an unknown id does. |
| `from`, `to` | Optional `Y-m-d`. Defaults to the last 12 months. `to >= from`, and the range spans at most 24 months. |

It requires `organization.view` and the standard `auth:sanctum`, `active`, `mfa`, `abilities.http` and `tenant` middleware.

## Response

```json
{
  "data": {
    "scope": "organization | departments | none",
    "filters": { "country": "MX", "legal_entity": null, "from": "2025-07-01", "to": "2026-06-15" },
    "generated_at": "2026-06-15T10:00:00+00:00",
    "widgets": {
      "total_employees":        { "state": "ok", "value": 42 },
      "active_employees":       { "state": "ok", "value": 40 },
      "new_joiners":            { "state": "ok", "value": 3 },
      "upcoming_birthdays":     { "state": "ok", "items": [{ "employee_id": "01J…", "name": "Ana Ruiz", "date": "2026-06-20", "in_days": 5 }] },
      "upcoming_anniversaries": { "state": "ok", "items": [{ "employee_id": "01J…", "name": "Omar Haddad", "date": "2026-06-25", "in_days": 10, "years": 5 }] },
      "workforce_trends":       { "state": "ok", "series": [{ "month": "2026-05", "headcount": 40, "hires": 2, "exits": 1 }] },
      "pending_leave_approvals": { "state": "unavailable", "module": "leave" }
    }
  }
}
```

## Authorization

- Every figure is computed over `EmployeeAccess::scopeEmployees`, the same row-level visibility the employee endpoints use:
  - Users with `employees.view.all` see organization-wide figures.
  - Department managers see only their managed departments, plus their own record.
- Users who have only `employees.view.self` (employees, payroll administrators, auditors) get `widgets: {}`. They receive no headcounts and no colleague names.
- Widget keys the user may not see are left out of the response, not returned as `null`.
- Upcoming lists contain only the public id, the name, the next date and the day offset, capped at 8 items and limited to active employees. Anniversaries also include the number of years. No year of birth, age, email or other profile field is returned.

## Data

The additive migration `2026_11_13_000100` adds these columns to `employees`:

- `hire_date` and `termination_date`.
- `birthday_md` and `hire_md`, holding month and day (`MM-DD`) with no year.

`Employee::booted()` keeps `birthday_md` in sync from the encrypted `date_of_birth`, so the database can find upcoming birthdays without decrypting every row. `hire_md` is kept in sync from `hire_date` the same way. The full date of birth stays encrypted.

Counts and trends each come from a single conditional-aggregate query, using half-open month bounds so they work on SQLite and MySQL. Every filter and date column is indexed by `(tenant_id, …)`.

## Modules not built yet

Pending leave approvals, today's attendance, absentees, pending onboarding tasks, contract expirations and the recruitment pipeline return `{ "state": "unavailable", "module": "…" }`. Only users with organization-wide access receive them. They switch to real figures when each module adds its aggregate to `DashboardService`. No figures are estimated or invented.
