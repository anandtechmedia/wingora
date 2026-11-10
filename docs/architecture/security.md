# Wingora security foundation

Status: implemented in `backend/` (Laravel 13, PHP 8.4, MySQL 8). HR modules are not implemented yet; departments, employees and compensation exist only as minimal, read-only records to prove authorization.

This document describes controls as built. It is not a certification; no external audit or penetration test has been performed.

## 1. Tenancy

| Control | Where |
| --- | --- |
| Shared database, every tenant-owned row has `tenant_id` | migrations |
| Tenant resolved **only** from the authenticated user; never from headers, subdomains or parameters | `app/Http/Middleware/ResolveTenant.php` |
| Global scope; querying tenant data without a tenant context **throws** (fail closed) | `app/Models/Concerns/TenantScope.php` |
| `tenant_id` assigned server-side on create, immutable on update, cross-tenant create blocked | `app/Models/Concerns/BelongsToTenant.php` |
| Tenant context set before route-model binding, so a foreign id resolves to 404 | `bootstrap/app.php` (middleware priority) |
| Policies re-check `tenant_id` equality on every record (defence in depth) | `app/Policies/*` |
| Public ULIDs (`public_id`) in URLs; integer ids never serialised | `app/Models/Concerns/HasPublicId.php` |

Scope bypasses (`withoutGlobalScope`) are limited to: authentication lookup (`TenantUserProvider`, `LoginService`, `PersonalAccessToken::tokenable`), role sync, append-only log inserts and platform tenant suspension.

## 2. Roles and permissions

Permissions are an enum (`app/Security/Permission.php`); each is registered as a Gate. Record-level rules live in Policies. There is **no `Gate::before` super-user bypass**.

System role permissions are always resolved from `app/Security/RoleCatalog.php`, so editing the `roles.permissions` column cannot widen a system role. A role can never hold permissions outside its scope (platform vs tenant).

| Role | Scope | MFA required | Summary |
| --- | --- | --- | --- |
| Platform Owner | platform | yes | Manage tenants and platform staff. **No tenant HR data.** |
| Platform Administrator | platform | yes | Manage tenants. **No tenant HR data.** |
| Organization Owner | tenant | yes | All tenant permissions. |
| HR Administrator | tenant | yes | Users, roles, departments, all employees, sensitive personal fields, audit log. No payroll. |
| HR Manager | tenant | yes | Departments and all employees (non-sensitive). No payroll. |
| Department Manager | tenant | no | Employees of departments they manage, plus self. |
| Employee | tenant | no | Own record only. |
| Payroll Administrator | tenant | yes | Compensation and sensitive pay fields. |
| Auditor | tenant | yes | Read audit log and security events; users list. No HR records. |

Country configuration adds `platform.country-rules.manage` / `platform.country-rules.approve` (both platform roles; approval is maker-checker), `organization.entities.manage` (Organization Owner, HR Administrator) and `holidays.manage` (Organization Owner, HR Administrator, HR Manager). See `country-configuration.md`.

Role assignment (`app/Security/RoleAssigner.php`) blocks privilege escalation: an actor can only grant roles whose permissions they already hold, only an Organization Owner can grant Organization Owner, and users cannot change their own roles.

Row-level employee/department visibility is defined once in `app/Security/EmployeeAccess.php` and used by both list queries and single-record policies.

## 3. Authentication

**Browser (first-party React app):** Sanctum SPA mode. `GET /sanctum/csrf-cookie`, then `POST /api/v1/auth/login`. Session cookie is encrypted, `HttpOnly`, `SameSite=Lax`, `Secure` by default; session id regenerated on login and MFA completion; sessions have an idle lifetime (`SESSION_LIFETIME`) and an absolute lifetime (`SESSION_ABSOLUTE_LIFETIME`, default 12 h). CSRF is enforced on all state-changing cookie-authenticated requests. Requests from non-allow-listed origins get no session and cannot use browser login.

**Mobile and integrations:** `POST /api/v1/auth/tokens` returns a Sanctum bearer token (prefix `wgr_`, stored hashed).

- Abilities: `api:read` (default) and/or `api:write`; enforced per HTTP method by `EnforceTokenAbilities`. Wildcard abilities are rejected.
- Expiry: per-token `expires_at` (default 7 days, `API_TOKEN_TTL_MINUTES`) plus a global ceiling (`SANCTUM_EXPIRATION`, 30 days) that also covers tokens created without an expiry.
- Revocation: current token, a specific own token, or all tokens. Password change/reset, user disablement and tenant suspension revoke all tokens (and database sessions). Expired tokens are pruned daily.
- At most `API_TOKEN_MAX_PER_USER` tokens per user; oldest are evicted.

**Login protection:** uniform error for unknown email / wrong password / locked / disabled; dummy hash check for unknown emails; account lock after 5 failures for 15 minutes; rate limits per email+IP (5/min) and per IP (20/min); separate limiters for MFA and password reset.

**MFA (TOTP, RFC 6238):** mandatory for privileged roles and optionally for all users of a tenant (`organization/security`). Until enrolled, privileged users can only reach account self-service endpoints. Secrets are encrypted at rest; codes cannot be replayed (last accepted time step stored); 10 recovery codes are stored hashed and consumed on use. Privileged users cannot disable MFA, and bearer tokens for MFA users require a TOTP code.

**Password reset:** enumeration-safe response, 30-minute single-use tokens (hashed by Laravel's broker), reset links point to `FRONTEND_URL`, strong password policy (12+ chars, mixed case, number, symbol, breached-password check in production). A successful reset unlocks the account and revokes all sessions and tokens.

## 4. Data protection

- Encrypted columns (Laravel `encrypted` cast, AES-256 with `APP_KEY`): `employees.national_id`, `bank_account_number`, `date_of_birth`, `users.mfa_secret`, `users.mfa_recovery_codes`.
- Sensitive fields are hidden from serialisation and served only by a dedicated, permission-checked endpoint that writes an audit entry.
- Mass assignment: explicit `$fillable` on every model; `Model::preventSilentlyDiscardingAttributes()` makes unexpected attributes throw; privileged fields are only set via `forceFill` in server code.
- SQL injection: Eloquent/query builder bindings only; `LIKE` wildcards escaped; no raw SQL with user input.
- Output encoding: API returns JSON only (`application/json`, `nosniff`, `default-src 'none'` CSP). The React app must not use `dangerouslySetInnerHTML` on API data.

## 5. Files

Private disk (`storage/app/private/secure` or a private S3 bucket); `serve` disabled, no public URLs. Uploads are validated by extension allow-list, content-detected MIME type, extension/content agreement and size limit; stored under a random name per tenant; SHA-256 recorded. Every download re-runs `StoredFilePolicy` (payroll files: payroll permission or the employee themself; identity/contract: sensitive-data permission or the employee) and is audited. Downloads are sent as attachments with a sandbox CSP.

## 6. Logging

- `audit_logs`: append-only (update/delete throw); records create/update/delete of auditable models with old/new values, and sensitive reads (sensitive fields, compensation, file downloads). Hidden, encrypted and salary values are redacted.
- `security_events`: append-only; logins, failures, lockouts, MFA events, token issue/revoke, password events, role changes, blocked escalation, access denials, tenant status changes, tenancy violations. Mirrored as JSON to the `security` log channel (`storage/logs/security-*.log`, 90 days) for shipping to a SIEM.
- Every response carries `X-Request-Id`, which is stored on both logs.

## 7. HTTP hardening

- CORS: only origins in `CORS_ALLOWED_ORIGINS`, credentials allowed, `*` ignored.
- Headers: CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, Permissions-Policy, COOP/CORP, HSTS on HTTPS, `Cache-Control: no-store` on API responses.
- `TRUSTED_PROXIES` and `TRUSTED_HOSTS` are explicit lists; no wildcard by default.
- Errors are `application/problem+json`; no stack traces unless `APP_DEBUG=true`.
- Production prohibits destructive DB commands (`migrate:fresh`, `db:wipe`).

## 8. Tests

`backend/tests/Feature/Security` (57 tests) covers tenant isolation, role/department/payroll authorization, token lifecycle, authentication, MFA, password reset, CSRF, sessions, CORS, headers, mass assignment, SQL injection, validation, encryption, audit and file access. CI runs them on SQLite and MySQL 8 on GitHub-hosted runners without containers (`.github/workflows/backend.yml`).

## 9. Known gaps (to address before production)

See the PR description / task report for the current list. Main items: per-tenant encryption keys and key rotation, SSO/SAML, WebAuthn, email verification, support-access ("break-glass") workflow, malware scanning of uploads, central SIEM shipping and alerting, log retention policy, external penetration test.
