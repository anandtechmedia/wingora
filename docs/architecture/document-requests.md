# Document requests

Additive migration `2026_12_10_000100_create_document_request_tables`. Both tables are tenant-scoped (`tenant_id` plus the `BelongsToTenant` global scope) and use composite `(id, tenant_id)` foreign keys to templates, employees and legal entities.

## Permissions

| Permission | Granted to | Allows |
| --- | --- | --- |
| `documents.request` | HR Admin, HR Manager, Department Manager, Employee, Payroll Admin | request documents for yourself, see and cancel your own requests, download what was delivered to you |
| `documents.approve` | Department Manager | decide the manager step for direct reports and departments you manage |
| `documents.manage` | HR Admin, HR Manager | see every request; decide the manager step on someone's behalf; final approval and delivery of non-salary documents |
| `documents.salary` | Payroll Admin | final approval and delivery of salary-bearing documents, and downloading them |
| `documents.configure` | HR Admin | templates |

The Organization Owner holds every tenant permission. Auditors get none. Nobody can decide or deliver a request they made or that is about themselves. Anything outside these rules returns `404` (or `403` for an action on a visible request).

## Flow

`pending_manager` → `pending_hr` → `approved` → `delivered`, with `rejected` (reason required) from either pending step and `cancelled` by the requester while pending. The manager step happens only when the template `requires_manager` and the employee's manager has a user with `documents.approve`; otherwise requests start at `pending_hr`. Every change requires the request `version` (row lock + compare; mismatch `409`).

## Templates

- `delivery: generated` renders a PDF letter from `body` with the built-in Payroll PDF writer (no new dependency). Placeholders: `employee.name|first_name|last_name|number|job_title|department|designation|hire_date|employment_type`, `entity.legal_name|country`, `today`, `request.number`, `answers.<field key>`, and `salary` for salary templates. Unknown placeholders are rejected when the template is saved. The writer uses WinAnsi Helvetica, so scripts outside Latin-1 (e.g. Arabic) are not rendered yet.
- `delivery: upload` takes a finished PDF/DOCX from HR at delivery.
- `fields` (up to 10): `key`, `label`, `type` (`text` ≤200, `textarea` ≤2000, `date` `Y-m-d`), `required`. Unknown answer keys are rejected.
- `legal_entity_id` limits a template to employees of one legal entity; `active: false` hides it from requesters.
- `includes_salary` requires a generated template that uses `{{salary}}`. The figure is typed in at delivery by a `documents.salary` holder, printed into the letter and never stored in the database or the audit trail.

## Data protection

Answers and rejection reasons are encrypted at rest (`answers` is `MEDIUMTEXT` so ten 2,000-character answers fit once encrypted). Delivered files are in the private `documents` file category; the generic `/files` endpoints refuse that category. Downloads go through `/document-requests/{id}/document` (requester or the final-step permission holder) and write `documents.downloaded`. Audit events: `documents.request_submitted`, `documents.request_decided`, `documents.request_cancelled`, `documents.delivered`, `documents.downloaded`, plus model events `documents.template_*` and `documents.request_*` with encrypted values redacted.

## API (`/api/v1`, session or token auth)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/document-templates` | requesters: active templates for their legal entity; configurers: all, with `body` and `placeholders` |
| POST / PATCH | `/document-templates`, `/document-templates/{id}` | `documents.configure`; partial updates are validated against the merged template |
| GET | `/document-requests` | `scope=mine|approvals|all`, `status` (or `open`), `template_id`, `q` (name, number, `#n`), `per_page` ≤100 |
| POST | `/document-requests` | `template_id`, `answers` |
| GET | `/document-requests/{id}` | includes `can.decide|deliver|cancel|download` |
| POST | `/document-requests/{id}/decide` | `decision: approve|reject`, `reason` (reject), `version` |
| POST | `/document-requests/{id}/cancel` | requester, `version` |
| POST | `/document-requests/{id}/deliver` | `version`; `salary` (salary templates) or multipart `file` (upload templates) |
| GET | `/document-requests/{id}/document` | delivered file |
