# Platform administration, support access, export and deletion

Platform staff (Platform Owner, Platform Administrator) work in `/api/v1/platform/*`. These routes never run in a
tenant context and never return HR data. The one exception is support access, which an Organization Owner must grant.

## Permissions

| Permission | Owner | Admin | Used for |
| --- | --- | --- | --- |
| `platform.tenants.view` / `.manage` | yes | yes | tenant list, detail, create, suspend/reactivate |
| `platform.billing.view` | yes | yes | subscription overview, usage monitoring |
| `platform.billing.manage` | yes | no | plan catalog, trial extensions, manual (invoiced) plans |
| `platform.support.access` | yes | yes | support views while a grant is active |
| `platform.health.view` | yes | yes | operational health |
| `platform.audit.view` | yes | yes | platform audit log (existing) |

Tenant side: `account.manage` (Organization Owner only) for support grants, export and deletion. Export and deletion
also require a fresh MFA code (`code` or `recovery_code`).

## API

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/platform/tenants?search=&status=&plan=&subscription_status=` | paginated, includes subscription summary |
| GET | `/platform/tenants/{id}` | subscription, usage, active support grant, scheduled deletion, legal hold |
| PATCH | `/platform/tenants/{id}/status` | `status` + required `reason`; suspension revokes all sessions and tokens |
| PATCH | `/platform/tenants/{id}/subscription` | `action=extend_trial` (`days` 1–90) or `assign_plan` (`plan`, optional `ends_at`); `reason`, `version` required. Stripe-billed subscriptions are refused (409) |
| GET | `/platform/subscriptions/overview` | counts by status/provider/plan, MRR per currency in minor units (never converted), trials ending in 7 days |
| GET | `/platform/usage?search=&sort=name\|employees\|ai_requests\|storage_bytes` | each organization's usage against plan limits |
| GET | `/platform/health` | database, cache, queue (pending, failed), scheduler heartbeat, webhooks (24h), failed exports |
| GET | `/platform/tenants/{id}/support` | whether a grant is active (no data) |
| GET | `/platform/tenants/{id}/support/{overview\|users\|employees\|audit\|payroll}` | read-only, requires an active grant |
| GET/POST | `/account/support-grants`, `POST /account/support-grants/{id}/revoke` | owner grants 1–72 hours with a reason; one active grant at a time |
| GET/POST | `/account/exports`, `GET /account/exports/{id}/download` | owner + MFA |
| GET/POST | `/account/deletion`, `POST /account/deletion/cancel` | owner + MFA + organization name |

`account/*` and `billing/*` stay available when billing is read-only (see `billing.md`).

## Support access

- Only GET views exist; support can never write. Staff never get the tenant API itself (`/api/v1/employees` etc.).
- Views expose names, job titles, departments, statuses, users and roles, plan and usage, and audit event names
  (no before/after values). Compensation, national IDs, bank details, disciplinary records, salary letters, documents
  and candidate files are never exposed, with or without a grant.
- `payroll` (run status, type, currency, headcount; no amounts or lines) and payroll audit events need
  `include_payroll` on the grant.
- Each view is written to the organization's own audit log (`support.accessed`, with the section), so the owner sees it.
- Grants expire on their own and can be revoked at any time.

## Data export

`POST /account/exports` queues `BuildTenantExport`. The ZIP (private disk, `exports/<tenant>/<export>.zip`) holds one
CSV per record type (decrypted, internal ids kept so rows can be related, CSV formula injection neutralized), users
with roles, and every stored file. Requests, downloads and failures are audited. Export and deletion requests share the MFA rate limit (5 per minute). Downloads expire after 7 days;
`wingora:exports:expire` (hourly) deletes the file.

## Account deletion

1. The owner types the organization name and an MFA code. Refused while the organization is on legal hold.
2. Deletion is scheduled 30 days ahead and can be cancelled until then. Renewal is cancelled in Stripe (the owner can
   resume billing after cancelling the deletion).
3. `wingora:tenants:purge-deleted` (daily) first locks and rechecks the request and tenant (a cancellation or legal
   hold committed before then always wins) and marks the request `purging`, after which it can no longer be cancelled.
   It then ends any live Stripe subscription immediately, deletes stored files, and only then the database rows. If
   Stripe or file storage fails, nothing else is deleted and the next run retries. It deletes every tenant-owned row, users and their tokens/sessions, and all
   stored files and exports. Kept: an anonymized tenant row (`status=deleted`, so nobody can sign in), the subscription
   and invoices (App Wings' financial records), processed webhook ids, the deletion request and a platform audit entry
   with row and file counts. The organization's audit chains are anchored at their last entry so chain verification
   still passes. Organizations placed on legal hold during the wait are skipped.

## Health

The scheduler writes a heartbeat every minute; the health check reports `degraded` if it is older than 5 minutes.
Health never returns configuration values, hostnames or credentials.
