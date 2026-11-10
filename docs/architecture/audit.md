# Audit and compliance framework

Status: implemented in `backend/`.

**Scope of claims:** this is an engineering control set that supports future compliance work. It does **not** establish or certify SOC 2, ISO 27001, GDPR, or any other legal or regulatory compliance. No external audit has been performed.

## What is recorded

Everything goes through one writer, `AuditLog::record()`. It is called:
- automatically by the `Auditable` model trait (created/updated/deleted), and
- explicitly for reads, workflow steps and security events.

`SecurityEvent::record()` mirrors security events into the audit trail. Pass `audit: false` when the caller already wrote its own audit entry.

| Category (`AuditCategory`) | Examples |
| --- | --- |
| `authentication` | `auth.login_succeeded`, `auth.login_failed`, `auth.account_locked`, MFA and password events, `auth.logout` |
| `api_tokens` | `auth.token_issued`, `auth.token_revoked`, `auth.tokens_revoked_all` |
| `authorization` | `authz.denied`: every denied policy/gate decision for an authenticated user |
| `permissions` | `user.roles_changed`, `authz.role_escalation_blocked`, `role.*` |
| `employee_profile` | `employee.created/updated/deleted`, `employee.sensitive_viewed` |
| `employee_documents` | `file.downloaded`, `stored_file.created/deleted` |
| `leave` / `attendance` / `payroll` | `AuditEvents::LEAVE_REQUEST_APPROVED`, `ATTENDANCE_ADJUSTED`, `PAYROLL_RUN_PREPARED/APPROVED`, `compensation.viewed`. The modules don't exist yet; they must use these names |
| `country_rules` | `rule_set.*`, `rule_version.*`, `country_rules.approved` |
| `administration` | tenants, users, legal entities, locations, calendars, retention changes, integrity checks, pruning |
| `data_export` | `audit.exported`, `AuditEvents::DATA_EXPORTED` for future exports |

Each entry stores:

| Field | Meaning |
| --- | --- |
| `public_id` | ULID used by the API |
| `tenant_id` | Tenant; null for platform-level entries |
| `actor_user_id` | Who performed the action |
| `actor_type` | `user`, `unauthenticated` (e.g. failed login for a known account), `anonymous` or `system` |
| `created_at` | Timestamp, UTC |
| `category`, `event` | What kind of action and the action itself |
| `outcome` | `success` or `failure` |
| `subject_type`, `subject_public_id` | The affected resource |
| `old_values`, `new_values` | Before/after values where relevant |
| `metadata` | Extra context, e.g. export filters or a file's category |
| `ip_address`, `user_agent`, `http_method`, `route` | Request context |
| `request_id` | Correlation id; the same value as the `X-Request-Id` response header |

## Data minimisation

`App\Audit\Redactor` runs on every before/after/metadata payload, recursively. It masks:
- Passwords, secrets, `*_token` and API keys.
- MFA secrets and codes, including recovery codes.
- National IDs, bank account numbers and dates of birth.
- Tax and registration identifiers, passport and document numbers, SIN and SSN.
- Authorization headers and cookies.
- Any value that looks like a bearer or Sanctum token.

Long strings are truncated. Hidden and encrypted model attributes are also redacted at the model layer. File downloads record the file id, category, MIME type and size, never its contents.

## Integrity

- **The application cannot change entries.**
  - Model `updating` and `deleting` hooks throw.
  - `AppendOnlyBuilder` makes bulk `update()`, `delete()`, `increment()` and `decrement()` through Eloquent throw.
  - There is no API to edit or delete entries.
- **Hash chains.** Entries are linked per tenant and category (`t{tenant}:{category}`, `platform:{category}`).
  - Each entry has a `sequence` and `prev_hash`, plus a `hash` = HMAC-SHA256 over a canonical encoding of the entry. The key is `AUDIT_HMAC_KEY`, or a key derived from `APP_KEY` if unset.
  - `audit_chains` stores the chain head (last sequence and hash) and the retention anchor.
  - Writes take a row lock on the chain head, so sequences never collide.
- **Verification:** `GET /api/v1/audit-logs/integrity`, `GET /api/v1/platform/audit-logs/integrity`, or `php artisan wingora:audit:verify [--tenant=]`. It detects:
  - Modified entries.
  - Deleted or re-ordered entries.
  - Truncation of the newest entries.
  
  Each verification is itself audited.
- **Limits:** a database administrator who can rewrite both the entries and `audit_chains` **and** knows the HMAC key could forge a consistent chain. Recommended production hardening:
  - Run the application with a MySQL user that has only `INSERT` and `SELECT` on `audit_logs`, and let a separate maintenance user run pruning.
  - Keep `AUDIT_HMAC_KEY` in the secrets manager, separate from `APP_KEY`.
  - Ship entries to write-once (WORM) storage or a SIEM.

## Access

| Permission | Roles | Allows |
| --- | --- | --- |
| `audit.view` | Organization Owner, HR Administrator, Auditor | Search, view, summary, integrity check and retention settings for **own tenant only** |
| `audit.export` | Organization Owner, Auditor | CSV/JSON export |
| `audit.retention.manage` | Organization Owner | Change retention periods and legal hold |
| `platform.audit.view` | Platform Owner, Platform Administrator | Platform-level entries (`tenant_id` null) only |
| `platform.audit.export` | Platform Owner | Export platform entries |

Tenant isolation:
- Tenant audit queries run through the fail-closed tenant global scope, and `{auditLog}` route binding is tenant-scoped (another tenant's id returns 404).
- Platform staff cannot use tenant audit routes. The platform endpoints filter to `tenant_id IS NULL`.

## Reports and export

- `GET /api/v1/audit-logs`: cursor-paginated search. Filters:
  - `category`, `event` (exact, or a prefix with a trailing `*`), `outcome`.
  - `actor_id` (user public id), `subject_type`, `subject_id`.
  - `request_id`, `ip`, `from` / `to` (`Y-m-d`).
- `GET /api/v1/audit-logs/{id}`: one entry, including its `sequence` and `hash` as evidence.
- `GET /api/v1/audit-logs/summary`: counts per category and outcome, with the same filters.
- `GET /api/v1/audit-logs/export?format=csv|json`: streamed, at most `AUDIT_EXPORT_MAX_ROWS` (default 50,000) rows, rate-limited.
  - CSV cells that start with `= + - @`, tab or CR get a leading `'` to prevent spreadsheet formula injection.
  - Every export writes an `audit.exported` entry with its format, filters and row count.

## Retention

- Defaults are in `config/audit.php`. They are configurable defaults, **not legal advice**; confirm the required periods for each jurisdiction and contract.
  - Default: 2,555 days. Authentication and API tokens: 730. Authorization denials: 365. Payroll, employee profile and documents: 2,555.
- Tenants may override per category within `AUDIT_RETENTION_MIN_DAYS` and `AUDIT_RETENTION_MAX_DAYS` (365–3,650 by default) via `PUT /api/v1/audit-retention`.
- `legal_hold: true` stops all pruning for the tenant.
- `php artisan wingora:audit:prune [--dry-run]` runs daily at 02:30 and is the only supported way to delete entries. Per chain, it:
  1. Deletes the oldest entries older than the cutoff (always a prefix of the chain).
  2. Moves the chain anchor forward, so verification still passes.
  3. Writes an `audit.retention_pruned` entry with the count, the last sequence removed and the anchor hash.

## Known gaps

- HMAC key rotation is not supported yet; entries don't record a key id.
- No database-level `INSERT`/`SELECT`-only grants are applied. This is a deployment task, documented above.
- Deleting a tenant row cascades to its audit entries. Tenant deletion is not implemented; it will need an archive-first procedure.
- Writing to a chain is serialised per tenant and category, which may need revisiting at high write volumes.
- Leave, attendance, payroll and data-export modules don't exist yet. Only their event names and categories are defined and tested.
