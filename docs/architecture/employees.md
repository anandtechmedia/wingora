# Employee Management

Server-enforced employee directory, profiles, employment history, documents, emergency contacts,
HR notes and disciplinary records, policy acknowledgements and CSV import/export. All routes are under
`/api/v1`, behind `auth:sanctum`, `active`, `mfa`, token abilities and `tenant` middleware. Every lookup is
tenant-scoped; ids are public ULIDs and a foreign or unknown id behaves as not found (404) or matches nothing.

## Who sees what

| Data | Who | Ability |
| --- | --- | --- |
| Directory card (name, number, title, designation, department, entity, office, work email, status, manager, dates) | Anyone who may view the employee (`employees.view.all`, managed departments, or self) | `view` |
| Employment history | Same as directory | `view` |
| Personal details, emergency contacts | The employee, `employees.manage`, `employees.sensitive.view` (not line managers) | `viewPersonal` / `updatePersonal` |
| National ID, bank account, date of birth | `employees.sensitive.view` (edit also needs `employees.manage`); every view audited | `viewSensitive` / `updateSensitive` |
| Notes and disciplinary records | `employees.disciplinary.view` / `.manage`, **never** the employee concerned, whatever their role | `viewRecords` / `manageRecords` |
| Documents | Existing `StoredFilePolicy` (identity/contract: sensitive viewers and owner; payroll: payroll viewers and owner) | per file |
| Import | `employees.import` + `employees.manage` | `import` |
| Export | `employees.export` | `export` |
| Policies | Published: everyone in the tenant. Drafts and management: `policies.manage` | — |

Default roles: Organization Owner has everything. HR Administrator gets all new permissions. HR Manager gets
`employees.disciplinary.view`, `employees.disciplinary.manage` and `employees.export`. Other roles get nothing new.

`GET /employees/{id}` returns `abilities` so clients can hide controls; the server enforces every one regardless.

## Endpoints

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/employees` | `search` (name, preferred name, number, work email), `department`, `designation`, `legal_entity`, `work_location`, `manager`, `country`, `status`, `employment_type`, `sort` (`name`, `employee_number`, `hire_date`, prefix `-` for desc), `per_page` ≤ 100 |
| POST | `/employees` | Create. Required: `employee_number` (unique per tenant), `first_name`, `last_name` |
| GET / PATCH | `/employees/{id}` | Profile with abilities / update. `effective_date` and `note` go into history |
| GET | `/employees/{id}/history` | Paginated, newest first |
| GET / PATCH | `/employees/{id}/personal` | Preferred name, nationality, personal email, mobile, home address (encrypted) |
| GET / PATCH | `/employees/{id}/sensitive` | National ID, bank account, date of birth (encrypted) |
| GET / POST / PATCH / DELETE | `/employees/{id}/emergency-contacts[/{contact}]` | Max 5, one primary, encrypted |
| GET / POST / DELETE | `/employees/{id}/records[/{record}]` | `type` `note` or `disciplinary`; disciplinary needs `severity`. Body encrypted, delete is soft |
| GET | `/employees/{id}/documents` | Only files the caller may open, plus `meta.upload_categories`. Upload and download via `/files` |
| GET | `/employees/{id}/policies` | Published policies with this employee's `acknowledged_at` |
| GET | `/employees/export` | CSV with the list filters. Directory columns only; formula-injection safe; audited as `export.completed` |
| GET / POST | `/employees/import` | GET returns columns and limits. POST `file` (CSV ≤ 2 MB, ≤ 1,000 rows), `dry_run` |
| GET / POST / PATCH | `/designations`, `/departments` | Create/rename needs `departments.manage` |
| GET / POST | `/policies` | List / create draft |
| POST | `/policies/{id}/publish`, `/retire`, `/acknowledge` | Acknowledge is idempotent and only for published policies |

## Validation and history

- References (department, designation, manager, entity, office) resolve through tenant-scoped queries.
- A work location must belong to the chosen legal entity; both must be active when newly assigned; the
  employment type must be approved for that location on the effective date (country rules, see `country.md`).
- A manager cannot be the employee or anyone in their reporting chain.
- Changes to department, designation, title, manager, entity, office, employment type, status, hire or
  termination date add a history entry (`hire`, `transfer`, `role_change`, `manager_change`, `status_change`,
  `termination`, `rehire`) holding names, never internal ids. Setting `terminated` fills the termination date.
- Every model change is also in the audit log, with encrypted fields masked.

## CSV import

Columns: `employee_number, first_name, last_name, preferred_name, work_email, job_title, department,
designation, manager_employee_number, legal_entity_id, work_location_id, employment_type, status, hire_date`.
Only the first three are required. Departments and designations are matched by name and must exist.
`manager_employee_number` may reference an existing employee or another row in the same file.
A dry run reports errors per line. A real import creates nothing if any row is invalid (HTTP 422).
Imports create employees only, not user accounts; existing employee numbers are rejected, not updated.

## Performance

The list uses indexed tenant columns (`manager_id`, `designation_id`, `employment_type`, name) and eager-loads
relations (no N+1). Export streams in chunks of 500. Imports validate in memory with one lookup per reference
table before writing.
