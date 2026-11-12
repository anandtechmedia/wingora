# Web application shell

The responsive frame every Wingora web module renders inside: authentication pages, navigation,
workspace context, search, notifications and error handling. Built only from the design system
([design-system.md](design-system.md)); no new UI library.

![Dashboard, desktop](web-shell/dashboard-desktop.png)

## Routes

| Path | Page | Guard |
| --- | --- | --- |
| `/login` | Sign in | Signed-in users go to `/dashboard` |
| `/forgot-password` | Request a reset link | Same |
| `/mfa` | Authenticator or recovery code | Only while a sign-in awaits MFA |
| `/dashboard` … `/billing` | The 16 modules | Signed in **and** module permission |
| `/design-system` | Component preview | Signed in |
| `/access-denied` | 403 | Signed in |
| any other path | 404 | Signed in |

Signed-out users are sent to `/login` and returned to the page they asked for afterwards.
Return targets must be same-origin paths (`safeRedirect`), so the sign-in page cannot be used as an
open redirect.

## Modules and permissions

`@wingora/permissions` is the single list of modules (`modules.ts`) and the permission keys
(`permissions.ts`). Values in `Permission` match the backend `App\Security\Permission` enum exactly.

| Module | Visible with |
| --- | --- |
| Dashboard | `organization.view` |
| Employees | any of `employees.view.all`, `employees.view.department`, `employees.view.self` |
| Payroll | `payroll.view` |
| Reports | `reports.view`* or `audit.view` |
| Settings | any of `organization.manage`, `organization.security.manage`, `users.manage`, `roles.assign` |
| Billing | `billing.view`* **and** `organization.manage` |
| Documents | any of `documents.request`, `documents.approve`, `documents.manage`, `documents.salary`, `documents.configure` |
| Offboarding, Performance, Assets, Projects | `<module>.view`* |

\* `PendingPermission`: not defined by the backend yet. Each must be added to the backend enum and role
catalog with the same value when its module is built. Until then no real user holds them, so those
modules stay hidden outside the prototype.

A module the user lacks is left out of the sidebar, global search, dashboard, breadcrumbs and
notification center. Opening its URL directly renders Access denied in place. Rules fail closed:
an empty or unknown rule denies.

**This is presentation only.** The API authorizes every request with Laravel Policies and tenant
scoping; the client never decides what data a user may read.

## Session

`SessionProvider` keeps the `/api/v1/auth/me` payload (`MeResponse` in `src/auth/types.ts`) in memory.
Nothing about the user is written to `localStorage`/`sessionStorage`; a reload signs out until the
API client restores the session from the Sanctum cookie. Transport is behind `AuthGateway`, matching
the backend flow: `login` → `202 {mfa_required}` → `login/mfa` → session.

**Prototype:** `src/mocks/` holds the only mock gateway and fixtures (three personas, organizations,
legal entities, notifications). They exist for visual prototyping and are replaced by
`@wingora/api-client`; no other backend responses are simulated.

## Layout

| Width | Navigation | Workspace selectors |
| --- | --- | --- |
| ≥ 1024 px | Full sidebar, collapsible to an icon rail | In the top bar |
| 768–1023 px | Icon rail | Above the page content |
| < 768 px | Top bar with a navigation drawer | Above the page content |

- Organization switcher and legal-entity selector are radio menus (`menuitemradio`). The selection is
  UI context only; the server still derives the tenant from the signed-in user.
- Global search: `Ctrl K` / `⌘ K` or the search button opens a combobox dialog over permitted modules
  (arrow keys, Home/End, Enter). People and record search arrive with the module APIs.
- Notification center: a drawer with All/Unread tabs and mark-as-read.

## Loading, errors and empty states

- Every module is a lazy chunk shown behind a `Suspense` loading state; unbuilt modules share the
  `coming-soon` placeholder chunk.
- An `ErrorBoundary`, reset on navigation, wraps the page area so a failing module leaves the shell
  usable and offers Try again. The router `errorElement` catches anything outside it.
- Error pages never display error details. Details go to the console in development only.
- Modules without an API show an empty state rather than invented data.

## Tests

`apps/web/src/app/shell.test.tsx` covers the sign-in → MFA → return flow, validation and focus,
uniform forgot-password response, redirect safety, per-role navigation, direct-URL 403, search and
notification filtering, workspace switching, sign-out, 404, the error boundary and an axe check.
`packages/permissions` tests the permission rules.

## Organization dashboard

`modules/dashboard/` renders `GET /api/v1/dashboard` ([API contract](dashboard.md)). It never computes figures from employee lists, and it shows no figures when the API is unavailable.

- **Data source:** `DashboardSourceProvider` supplies a `DashboardSource`.
  - `httpDashboardSource` calls the API.
  - `disconnectedDashboardSource`, the default, always rejects. The prototype then shows "Live figures need the Wingora API" instead of sample numbers.
  - Tests inject test doubles shaped like the API response.
- **Widgets:** the server decides which widgets the user receives. Any key it leaves out is not rendered. Widgets for modules that aren't built yet (`state: "unavailable"`) show "Available when {module} is connected".
- **Filters:**
  - Country (the four markets).
  - Legal entity, loaded from `GET /api/v1/legal-entities` and narrowed by country.
  - From/to dates using the design-system `DatePicker`.
  - Each change aborts the in-flight request. The previous figures stay on screen, dimmed, until the new ones arrive.
- **States:** a skeleton on first load, an error state with retry, an empty state for roles without headcount access, and an empty state for trends with no data.
- **Charts:** `TrendChart` is plain SVG with a `viewBox`, so it scales with its card. There is no chart library. The SVG is hidden from assistive technology, and a visually hidden table carries the same figures.

### Running against the API

Set `VITE_API_MODE=live` (see `apps/web/.env.example`). The app then uses `createLiveGateway()` for sign-in: Sanctum SPA cookies, `/sanctum/csrf-cookie`, then `/api/v1/auth/login`, `/login/mfa` and `/me`.

`vite dev` and `vite preview` proxy `/api` and `/sanctum` to `WINGORA_API_ORIGIN` (default `http://127.0.0.1:8000`), so cookies stay same-origin. Any other mode is the visual prototype, with mock sign-in and no dashboard figures.

## Not done yet

- Real API client, CSRF cookie bootstrap and session restore on reload.
- Multi-organization accounts (the backend has one organization per user).
- Backend permissions for the pending modules.
- Record-level search, real notifications and the module screens themselves.

## Attendance and leave
`modules/attendance` and `modules/leave` use `api/time.ts` (`TimeSource`, provided in `app.tsx`; disconnected unless `VITE_API_MODE=live`). Tabs appear only for the permissions the server grants. Approve and cancel buttons follow the `can_decide`/`can_cancel` flags the API sends with each request. Times are shown in the employee's zone exactly as the API returns them; the browser never converts them. Leave days are always counted by the server (`/leave/requests/preview`). See `attendance-leave.md`.

## Payroll preparation (web)

`modules/payroll` is backed by `api/payroll.ts` (`PayrollSource`, HTTP and disconnected implementations). It never calculates money in the browser: every amount arrives from the API as `{ amount, minor, currency }` and is only formatted for display.

- **Tabs** are chosen from permissions: My pay (`payslips.view.self`, `reimbursements.submit`), Payroll runs, Compensation and Loans (`payroll.view`), Reimbursements (`reimbursements.approve` or `payroll.manage`) and Setup (`payroll.configure`). Run actions (prepare, submit, approve, publish, export) are shown from the run's server-supplied `can` flags; the server enforces them regardless.
- **Source labels:** every line, structure component and totals row shows `SourceBadge` — Calculated, Entered (manual) or Provider. A disclaimer states that runs are prepared for review and are not a statutory payroll calculation.
- **Approvals:** the run detail shows the approval timeline per review cycle and level; approve/reject reuse `DecisionDialog`.
- **Files:** payslip PDFs, receipts and CSV exports download through `apiDownload`; receipts upload through `/api/v1/files` first.
- Without an API the module shows "Payroll needs the Wingora API" instead of sample data.

## Recruitment and onboarding (web)

API reference: `docs/architecture/recruitment-onboarding.md` (backend). Client: `apps/web/src/api/recruitment.ts` (`RecruitmentSource`, `CareersSource`; disconnected sources in prototype mode).

- **Public careers site** — `/careers/:site` and `/careers/:site/:job` (`pages/careers-page.tsx`) sit outside the signed-in shell and need no session. The organization comes only from the careers address. Applying needs a CV and explicit consent; the form sends the consent version it showed (a stale version gets a 409 and a "reload" message) and includes a hidden honeypot field. The confirmation is the same whether or not the person applied before.
- **Recruitment** (`modules/recruitment`) — tabs by permission: Pipeline and Openings (`recruitment.view`), My interviews (`recruitment.interview` or `recruitment.view`), Settings (`recruitment.configure`).
  - Kanban columns scroll horizontally on small screens. Cards move with a "Move to" menu (keyboard, mouse and touch) that only lists open stages and sends the card's `version`; a 409 shows a conflict message and reloads the board. Hiring and rejection are separate, human actions.
  - The candidate drawer has the profile (consent version and retention date, private documents downloaded through the API), interviews (time shown in the interview's own time zone; colleagues' feedback only after you submit yours), offers (salary entered manually; accepting does not create an employee; "Hire" is a separate step) and AI assist.
  - AI drafts are labelled, stay "Needs review" until a person accepts or discards them, and never move, score, reject or hire.
- **Onboarding** (`modules/onboarding`) — My tasks (`onboarding.tasks.self`), Employees onboarding with progress bars and stages (`onboarding.view`), Templates (`onboarding.configure`). Template tasks can request a document taken from the legal entity's country rules. HR accepts or returns submitted documents with a note.
- Buttons follow the `can` flags in each response; the server enforces every rule.

## Helpdesk (web)

`modules/helpdesk` uses `api/helpdesk.ts` (`HelpdeskSource`, provided in `app.tsx`) against the API in `docs/architecture/helpdesk.md` of the backend.

- **Tabs by permission:** Queue (`helpdesk.agent` or `helpdesk.manage`), My tickets (`helpdesk.submit`), Knowledge base (submit, agent or manage) and Settings (`helpdesk.configure`). The nav entry requires any of the four.
- **Tickets:** server-paginated list with status, priority, scope (`queue`/`assigned`) and overdue filters; a drawer shows the description, private attachments, the conversation, and only the controls the server's `can` and `next` flags allow. Every mutation sends the ticket `version`; on failure (for example a `409` conflict) the drawer reloads so the next attempt uses fresh data.
- **Internal notes** are offered only when `can.internal_notes` is true and are highlighted; the API never returns them to requesters.
- **Text** (descriptions, comments, article bodies) is rendered as plain text with preserved line breaks, never as HTML.
- **Knowledge base:** FAQs expand inline; articles open in a drawer. Managers create drafts, edit (the form appears only after the full article loads), publish, unpublish and delete.
- **Settings:** due-by hours per priority (empty means no target; these are internal targets, not contractual service levels) and categories with restriction and named agents.

## Document requests (web)

`modules/documents` uses `api/documents.ts` (`DocumentsSource`, provided in `app.tsx`) against the API in `docs/architecture/document-requests.md`.

- **Tabs by permission:** My requests (`documents.request`), Approvals (`documents.approve`, `documents.manage` or `documents.salary`) and Templates (`documents.configure`). Approvals defaults to open requests waiting on the user; `documents.manage` holders can switch to every request they can see.
- **New request:** only active templates are offered; the template's questions render as typed inputs (short text, long text, date) and required ones gate submission. Employees never enter a salary figure.
- **Request drawer:** shows the steps, answers and rejection reason, and only the actions the server's `can` flags allow: approve, reject (reason required; an approved request can only be rejected), deliver (a typed salary figure for salary letters, which is printed but never stored, or an uploaded PDF/DOCX), cancel and download. Every mutation sends the request `version`; the drawer reloads after any attempt.
- **Templates:** name, delivery, description, up to 10 questions, letter text with the allowed placeholders listed beside it (unknown placeholders are rejected by the server), manager step, salary and active flags. Restricting a template to one legal entity is supported by the API but not yet offered on screen.


## Billing and account (`modules/billing`)

Backed by `api/billing.ts` (`BillingSource`), which mirrors the Billing and Account APIs in
`docs/architecture/billing.md` and `platform.md`. The server enforces every rule below; the page only hides what the
server would refuse.

- **Tabs by permission:** *Plan and usage* and *Invoices* need `billing.view`; plan changes, cancellation and the
  Stripe Customer Portal need `billing.manage`; *Account* (support access, data export, deletion) needs
  `account.manage`. The Billing menu entry needs `billing.view` or `account.manage`.
- **Payments stay on Stripe:** subscribing opens Stripe Checkout and payment methods open the Customer Portal.
  `redirect()` follows only `https://*.stripe.com` URLs. Card details never reach Wingora.
- **Plan changes:** a live Stripe subscription changes in place with its `version` (a concurrent change returns 409).
  Upgrades apply now; downgrades are explained as applying at renewal. Manual (invoiced) plans show a contact note.
- **Read-only banner:** `/me` carries the `billing` summary. While access is read-only, or a payment has failed, every
  page shows a banner, with a link to Billing for those who can open it.
- **Account:** support access is granted for 1–72 hours with a reason and an explicit payroll opt-in, and can be
  revoked. Export and deletion need a verification code (a 6-digit code is sent as `code`, anything else as
  `recovery_code`); deletion also needs the organization name typed exactly, is blocked under legal hold, and can be
  cancelled until the purge starts.

## Platform console (`modules/platform`)

Backed by `api/platform.ts` (`PlatformSource`), which mirrors the platform administration API in
`backend/docs/architecture/platform.md`. Those endpoints return Laravel's plain paginator, which
`fromPaginator` (`api/http.ts`) converts to the `{ data, meta }` shape the tables use.

- **Who sees it:** platform staff only. The menu entry needs `platform.tenants.view`,
  `platform.billing.view`, `platform.audit.view` or `platform.health.view`, none of which an
  organization role holds. Platform staff have no organization, so the organization and legal-entity
  selectors are hidden, and `/` sends every user to the first module they may open (`homePath`).
- **Organizations:** search and filter, then a side panel with status, subscription, usage, support
  access, legal hold and scheduled deletion. Suspending or reactivating needs `platform.tenants.manage`
  and a reason. Extending a trial or assigning a plan needs `platform.billing.manage` (Platform Owner)
  and sends the subscription `version`, so a concurrent change is refused instead of overwritten.
- **Support access:** shown only while the organization owner's grant is active. Sections come from the
  server (`payroll` only when the owner allowed it); every view is audited in the organization's log.
- **Subscriptions, usage, audit log and health:** read-only views of the platform API. Revenue is per
  currency and never converted. The audit export needs `platform.audit.export`.

As everywhere else, the console only hides what the server would refuse; the server enforces every rule.
