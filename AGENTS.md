# AGENTS.md — Wingora Development Contract

This is the primary contract for every AI coding agent (and human) working on **Wingora**. It is binding. Detailed standards live under [`docs/`](docs/). When this file and a detailed document conflict, this file wins; raise the conflict in your task report.

> Directories referenced below follow the approved target layout. If one does not exist yet, create it only when your task requires it, following these conventions.

---

## Mandatory rules

1. **Inspect existing code before modifying it.** Read the surrounding module, its tests and the relevant `docs/` page first.
2. **Reuse existing components**, services, packages and patterns. Search before you write.
3. **Avoid unnecessary dependencies.** Justify every new one in the task report (§14).
4. **Avoid duplicate implementations.** One source of truth per concern (see §3, §17).
5. **Never expose secrets.** No credentials, keys, tokens or production data in code, logs, fixtures, commits, PRs or chat.
6. **Never bypass authorization.** Every endpoint, job, command and query enforces policy checks.
7. **Never access another tenant's data.** No unscoped queries on tenant data. No exceptions without written approval.
8. **Never make unapproved destructive migrations.** Dropping or renaming columns or tables, or deleting data, needs explicit approval.
9. **Never introduce breaking API changes without versioning** (§5).
10. **Never deploy untested code to production** (§15, §19).
11. **Never claim security certification or legal compliance without evidence.** Write "designed to support SOC 2 / GDPR / PDPL", never "SOC 2 compliant", unless an audit report exists.
12. **Do not modify unrelated functionality.** Keep changes minimal and scoped to the task.
13. **Wait for approval** when the task says so, or before structural, destructive or production-affecting changes.

---

## 1. Product vision and markets

**Wingora — People. Progress. Performance.** An enterprise-grade, multi-tenant HR SaaS owned by **App Wings LLC** ([appwings.com](https://www.appwings.com)).

- **Markets:** United States, United Arab Emirates, Canada, Mexico.
- **Languages:** en (US/CA), es-MX, fr-CA, ar (RTL).
- **Product areas:** Core HR, Workforce, Time Off, Compensation, Talent, Lifecycle, Performance, Operations, Intelligence, Travel, Reports, Platform, AI.
- **Priorities, in order:** security → correctness → performance → UX.

## 2. Approved technology stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13, PHP 8.4, MySQL 8, Redis (cache, queue, session) |
| Web | React, TypeScript (strict), Vite, Tailwind CSS |
| Mobile | React Native, Expo, TypeScript (strict) |
| Monorepo | pnpm workspaces (pinned through `packageManager`), Node LTS from `.nvmrc` |
| Server UI (legacy) | Blade + Tailwind inside `backend/`, kept until React reaches parity |

- **Prohibited:** Docker or any container-based infrastructure; other package managers (npm, yarn); other frameworks or state libraries without approval.
- Upgrading a major version of any of the above requires approval.

## 3. Architecture and folder conventions

```
apps/web          React SPA              apps/mobile       Expo app
packages/*        @wingora/{types,api-client,validation,business-logic,
                  permissions,design-tokens,localization,utilities}
backend/          Laravel modular monolith
docs/architecture Architecture decisions and diagrams
deploy/           Non-container deployment templates
```

- **Dependency direction:** apps → packages → `types`/`utilities`.
  - Apps never import other apps. Packages never import apps.
- **Backend modules** live in `backend/app/Modules/<Area>/`, with subfolders `Models`, `Actions`, `Http`, `Policies`, `Events`, `Jobs`, `Contracts` and `Routes`.
  - Modules talk to each other **only** through `Contracts/` interfaces or domain events. A module never queries another module's tables.
  - Legacy code outside `Modules/` moves in behaviour-neutral, per-module PRs. Do not move it opportunistically.
- **Shared kernels:** Tenancy, Identity (auth/RBAC), Audit, Documents, Billing, Countries, AI.
- **Naming:**
  - PHP: PSR-12 enforced by Pint; actions are verbs (`ApproveLeaveRequest`); events use the past tense (`LeaveRequestApproved`).
  - TS: kebab-case files, PascalCase components and types, camelCase values.
- Details: `docs/architecture/`.

## 4. Multi-tenancy requirements

- **Shared schema.** Every tenant-owned table has an indexed, constrained `tenant_id`.
- **Fail closed.** Tenant models use `BelongsToTenant`. Querying with no tenant context throws; it never returns all rows.
- **Tenant comes from the credential only** (session, token, API key or public slug). Never trust a client-supplied tenant ID or header.
- **Background work:** queued jobs, commands and scheduled tasks carry `tenant_id` and run inside `Tenancy::run()`.
- **Scoped access:** validation rules (`exists`, `unique`), caches, file paths, search indexes and logs are tenant-scoped (key prefix `t:{tenant_id}:`).
- **`withoutGlobalScopes()`** is allowed only in platform/super-admin or authentication code, with a comment and a test.
- **Every new module ships a tenant-isolation test:** tenant A cannot read, write, list or reference tenant B's records.

## 5. API standards

- REST under `/api/v{n}/`, with plural kebab-case resources.
- **Breaking changes require a new version.** The old version gets `Deprecation` and `Sunset` headers and at least 6 months' notice.
- **JSON format:**
  - Fields are `snake_case`.
  - Timestamps are ISO-8601 UTC.
  - Money is `{ "amount": "1234.50", "currency": "USD" }`, with a string decimal.
  - Countries use ISO 3166-1 alpha-2 codes; locales use BCP-47.
- **Responses:**
  - Success: `{ "data": …, "meta": { … } }`, with cursor pagination and `updated_since` for sync.
  - Errors: **RFC 9457** `application/problem+json` with `type, title, status, detail, code, errors, request_id`. Error codes are stable and listed in `@wingora/types`.
- **Headers:** `X-Request-Id` (echoed or generated), `Idempotency-Key` on creating POSTs, `Accept-Language`.
- **The OpenAPI 3.1 spec is generated from the backend** and is the contract. `@wingora/types` is generated from it. Contract drift fails CI.
- **Never** return encrypted or sensitive fields (bank, tax or national IDs, secrets) unless an endpoint was approved specifically for them.

## 6. Authentication and authorization

- **One identity system** (backend `Identity` module):
  - Argon2id hashing, lockout after repeated failures, TOTP 2FA (tenants can make it mandatory).
  - SSO (SAML/OIDC) and SCIM plug in here.
- **Credentials per client:**
  - Web: Sanctum SPA cookie sessions (Secure, HttpOnly, SameSite=Lax, CSRF). No bearer tokens in browsers.
  - Mobile: least-privilege Sanctum tokens with expiry, stored in `expo-secure-store`, revoked on password change.
  - Integrations: scoped, hashed, expiring, IP-restricted API keys, kept separate from user tokens.
- **RBAC:**
  - Backend permissions are the single source of truth. Policies or gates are enforced on **every** route, action and job.
  - `@wingora/permissions` is generated and only hides UI. It is never a security control.
- **Audit:** authentication events, permission changes and all writes are recorded with tenant, actor, IP, user agent and `request_id`.

## 7. Database design standards

- **Tables and keys:**
  - Table names are snake_case plural; foreign keys are `<singular>_id` with constraints.
  - `tenant_id` comes **first** in composite indexes.
- **IDs:** bigint primary keys internally. Externally exposed records use a `public_id` ULID; never expose sequential IDs in new APIs.
- **Types:**
  - Money is `decimal(15,2)` plus `currency char(3)`; rates are `decimal(9,4)`; never floats.
  - Timestamps are stored in UTC.
  - Collation is `utf8mb4_unicode_ci`.
- **Personal data:** use encrypted casts and never index them; add a keyed-hash column when lookups are needed. HR records use soft deletes; hard deletes only through retention or erasure jobs.
- **Migrations:**
  - Forward-only in staging and production; follow **expand → migrate → contract** so deploys need no downtime.
  - Each migration is reversible locally. Destructive steps need approval (Mandatory rule 8).
  - Prevent N+1 queries with eager loading; `preventLazyLoading()` is enabled outside production.

## 8. Country configuration rules

- **Country logic lives in country packs** (`backend/config/countries/{us,ae,ca,mx}.php` plus classes implementing `CountryPack`): holidays, statutory leave, payroll and tax calculators, ID formats, currency and locale defaults.
- **Never hard-code country rules** (`if ($country === 'AE')`) in feature code. Resolve the pack from the employee's **legal entity**, not from the tenant or the user's locale.
- **Statutory rules are versioned by effective date**, so historical payroll recomputes exactly.
- **Statutory calculations** (tax, social security, WPS, CFDI, gratuity) must cite their source and have tests with worked examples. They are marked *unverified* until a qualified payroll or tax professional for that country signs off.
- **Data residency:** a tenant's region decides where its data is stored. Never copy data across regions without approval.

## 9. Secure coding requirements

- Follow **OWASP ASVS Level 2** as the baseline.
- **Input and output:**
  - Validate every input with Form Requests or schemas; use parameterised queries only.
  - Escape output; no raw HTML from users.
- **Headers:** strict CSP with nonces, HSTS, `X-Frame-Options: DENY`, `nosniff`. CORS is limited to the environment's own origins. Trusted hosts and proxies are pinned per environment; never `*` outside development.
- **Outbound requests** (webhooks, integrations, AI) go through `UrlGuard`: HTTPS only, public IPs only, no redirects (SSRF protection).
- **Files:**
  - Private storage only, behind short-lived signed URLs after a policy check.
  - Validate MIME type and size; paths are tenant-prefixed.
- **Secrets:** environment variables only. `.env*` files containing secrets are git-ignored, and secret scanning runs in CI.
- **Logging:** never log secrets, tokens, passwords, full card or bank numbers, or government IDs; redact them.
- **CSV and Excel exports** neutralise formula injection.
- **Rate-limit** authentication, public and integration endpoints.

## 10. UI/UX design standards

- **Design tokens** (`@wingora/design-tokens`) are the only source of colour, spacing, typography and radius. No hard-coded hex values or magic numbers.
- **Consistent layout:** sidebar grouped by the 13 product areas, page header, filters, table, detail drawer or page.
- Every list has empty, loading and error states. Every destructive action needs confirmation.
- **Text and formatting:**
  - All user-facing text comes from `@wingora/localization`; no hard-coded strings.
  - Format numbers, dates and currency with `Intl`.
  - RTL: support Arabic with logical CSS properties (`ms-`, `me-`, `start`, `end`).
- **Responsive:** mobile-first; web pages work from 360 px wide.

## 11. Performance standards

- **Budgets (p95, production):**
  - API reads under 300 ms, writes under 500 ms.
  - Web initial JS under 200 KB gzip per route; LCP under 2.5 s.
  - Mobile cold start under 3 s.
- **Database:**
  - No N+1 queries. Paginate every list, using cursor pagination for large or synced data.
  - Aggregate in the database, not in PHP. Add indexes for every new filter or sort path.
- **Queues:** anything slower than about 200 ms or involving an external service (mail, PDFs, webhooks, exports, AI) runs on Redis queues (`high`, `default`, `webhooks`, `reports`, `mail`).
- **Caching:** cache expensive reads in Redis with tenant-scoped keys and explicit invalidation.
- **Frontend:** code-split by route; lazy-load heavy screens.

## 12. Automated testing requirements

| Scope | Tooling | Required for |
|---|---|---|
| Backend | PHPUnit: unit, feature, **tenant isolation**, authorization, API contract | Every backend change |
| Packages | Vitest | Every package change |
| Web | Vitest + Testing Library; Playwright E2E | Components / critical flows |
| Mobile | jest-expo + React Native Testing Library | Screens and logic |
| Static | Pint, ESLint, `tsc --noEmit`, `composer audit`, `pnpm audit`, secret scan | Every PR |

- **Bug fixes** include a regression test.
- **Security-sensitive code** (auth, tenancy, permissions, payroll) needs both negative and positive tests.
- **Never delete or weaken a test to make CI pass.** Report the problem instead.

## 13. Accessibility requirements

- Target **WCAG 2.2 AA** for web and mobile.
- Use semantic HTML and labelled form controls; everything must work with a keyboard, with visible focus.
- Colour contrast is at least 4.5:1, enforced through tokens; never convey meaning by colour alone.
- **Mobile:** set `accessibilityLabel`/`accessibilityRole` on interactive elements, support dynamic type, and keep touch targets at least 44 pt.
- Run automated checks (axe for web) in tests. Do not claim conformance without an audit (Mandatory rule 11).

## 14. Dependency management

- **pnpm only.** Commit a single root `pnpm-lock.yaml`; `composer.lock` for the backend.
- Internal packages are `@wingora/*`, private, and linked with `workspace:*`.
- **Before adding a dependency**, check whether the framework, an existing package or about 50 lines of code would do. Then justify it in the task report:
  - why it is needed
  - its maintenance status
  - its licence (no GPL or AGPL in shipped code)
  - its size
- **Version policy:** pin to stable releases, not newly published versions. Weekly audits; fix critical and high advisories before release.

## 15. CI/CD procedures

- **GitHub Actions.** Jobs are path-filtered, and every PR must pass lint, type-check, tests, audits and the secret scan.
- **Branching:** `main` is protected. Use short-lived feature branches and PR review; never push directly to `main` and never force-push shared branches.
- **Staging:** deploys automatically on merge to `main`.
- **Production:**
  - Deploys from a version tag (`vX.Y.Z`) with **manual approval** in the GitHub `production` environment, and only from builds that passed staging.
  - Releases are zero-downtime and symlink-based (no containers): build, upload to `releases/<ts>`, `migrate --force`, switch the symlink, reload PHP-FPM, then `queue:restart`.
- **Workflows:** `backend`, `web`, `secret-scan` on every PR; `deploy` for staging, production and on-demand development.
- Details: `docs/architecture/environments.md` (environments, hosting, deployment, rollback).

## 16. Backup and recovery

- **Production MySQL:** automated encrypted daily backups plus point-in-time recovery; at least 30 days' retention, stored in a separate account or region.
- **Object storage:** versioning and lifecycle rules. Redis is treated as rebuildable; never store the only copy of data there.
- **Targets:** RPO 15 min or less, RTO 4 h or less. **Restore drills quarterly** into an isolated environment, with documented results.
- **Data copies:** production data is never copied to development or staging; use synthetic or anonymised data.
- **Encryption keys:** `APP_KEY` and other keys are backed up separately from data. Losing them makes encrypted columns unrecoverable.
- **Current shoestring hosting** meets these targets only partly (daily dumps, same account and region). The gaps and the upgrade path are listed in `docs/architecture/environments.md`.

## 17. Mobile and web shared-code requirements

- **Share:** types, API client, validation schemas, permission checks, business rules, formatters, localisation catalogs and design-token values.
- **Do not share:** UI components, navigation, storage, push notifications or file pickers. Each platform implements its own using shared logic.
- **`packages/*` must be platform-agnostic:**
  - No `react-dom`, `react-native`, `window`/`document` or Node built-ins.
  - Platform services are injected through adapters (for example `createApiClient({ baseUrl, tokenStore })`).
- **Duplicate logic** between web and mobile must be moved into a package before merging.

## 18. AI integration requirements

- **All model calls** go through the backend AI abstraction (`LanguageModel` contract). No direct provider SDK calls from features, the web app or the mobile app. Providers are configured by environment variables.
- **Context:**
  - Prompts and context are tenant-scoped and permission-filtered: AI never sees data the requesting user could not see.
  - Exclude sensitive fields.
- **Human control:**
  - AI output is advisory. It never approves, rejects, pays, terminates or changes records without explicit human confirmation.
  - Label AI-generated content in the UI.
- **Logging:** log interactions (tenant, user, feature, model, tokens, latency) without storing secrets. Respect tenant opt-out and data-residency settings.
- **Resilience:** degrade gracefully (rules or search fallback) when no provider is configured or it fails.
- **Safety:** guard against prompt injection from user-supplied content; never execute model output as code or queries.

## 19. Production deployment restrictions

- **Agents must never:**
  - deploy to production
  - run commands against production hosts or databases
  - read production secrets
  - change live infrastructure
- An agent may prepare a release (PR, tag proposal, changelog, migration plan). A named App Wings maintainer approves and runs it.
- **Environment isolation:** each environment has separate credentials, databases, Redis, buckets and domains. Staging must never reach production.
- **Production migrations** need a reviewed plan: backward-compatible, a backup taken, and a rollback path documented.
- **Emergency fixes** still go through PR and CI. They are never edited on the server.

---

## Task report (required for every development task)

End every task, in the PR description or final message, with:

```
Objective:                 what was asked and why
Files changed:             paths (grouped by app/package/module)
Implementation summary:    what changed and key decisions
Tests executed:            commands run and results (pass/fail counts)
Security implications:     authz, tenancy, data exposure, new attack surface (or "none")
Performance implications:  queries, payload size, caching, bundle size (or "none")
Outstanding issues:        known gaps, follow-ups, approvals still needed
```

Report only what you observed. If something was not run or verified, say so.
