# Recruitment and onboarding

Additive migration `2026_11_25_000100_create_recruitment_onboarding_tables`. Every table is tenant-scoped (`tenant_id` plus the `BelongsToTenant` global scope).

## Public and authenticated routes

| Surface | Prefix | Auth | Tenant from |
| --- | --- | --- | --- |
| Careers site | `/api/v1/public/careers/{site}` | none, `throttle:careers`; apply adds `throttle:careers-apply` | `recruitment_settings.careers_slug`, only when `careers_enabled` and the tenant is active |
| Recruitment | `/api/v1/recruitment/*` | Sanctum + MFA + `tenant` | the signed-in user |
| Onboarding | `/api/v1/onboarding/*` | Sanctum + MFA + `tenant` | the signed-in user |

The public API returns published openings only: no hiring manager, no counts, nothing about other applicants. A repeat application gets the same `202 {"status":"received"}`, so the endpoint can't be used to find out whether someone applied. A hidden `website` field must be empty.

## Permissions

| Permission | Granted to | Allows |
| --- | --- | --- |
| `recruitment.view` | HR Admin, HR Manager, Department Manager | openings where the user is the hiring manager or manages the department (HR: all) |
| `recruitment.manage` | HR Admin, HR Manager | every opening and application; add candidates, documents, erasure, hold |
| `recruitment.configure` | HR Admin | careers site, consent text, retention, AI switch, pipeline stages |
| `recruitment.offers` | HR Admin | create, approve, send and record offers. The approver must not be the author |
| `recruitment.interview` | HR Admin, HR Manager, Department Manager, Employee | feedback on interviews where the user is a panelist |
| `onboarding.view` / `manage` / `configure` | HR Admin (all); HR Manager (view, manage) | plans, document review, templates |
| `onboarding.tasks.self` | all staff roles | the user's own tasks, and tasks for their direct reports |

The Organization Owner holds every tenant permission. Auditors get none of these.

## Candidate data, consent and retention

- Candidate email and phone are encrypted. Duplicate applications are matched by a keyed HMAC of the email. Contact fields and feedback text are never written to the audit log.
- Each application stores the consent version, the time it was given and a keyed hash of the IP address. Editing the consent text creates a new version, and the careers form must send the version it showed.
- Documents are `stored_files` with category `recruitment` or `onboarding`. `/files` never serves or accepts these categories. They are reachable only through `/recruitment/documents/{id}/download`, `/recruitment/offers/{id}/letter` and `/onboarding/tasks/{id}/document`. Each of these re-checks access and audits the view.
- **Retention:** the retention period is a tenant setting (`retention_days`, default 365; this is a default, not a legal period). It starts counting when an opening closes or an application is rejected or withdrawn. `wingora:recruitment:purge` runs daily at 03:00. It anonymizes a candidate and deletes their files once every application that wasn't hired has passed its retention date. Candidates on hold and hired candidates are skipped.
- **Erasure:** `POST /recruitment/applications/{id}/erase` applies the same erasure on request.

## Pipeline, interviews, offers, hire

- **Stages:** each tenant edits its own stages. One stage is `hired` and one is `rejected`; neither can be reached by dragging. Moving a card requires the current `version`; a stale version returns `409`.
- **Interviews:** times are entered in the interview's own time zone and stored in UTC. Only panelists can submit feedback, once each, after the interview starts. A panelist can't see colleagues' feedback until they have submitted their own.
- **Offers** move `draft → approved → sent → accepted | declined`, with `withdrawn` possible from any open step. The salary is stored in minor units in the legal entity's currency and is not written to the audit log.
- **Hire:** `POST /recruitment/applications/{id}/hire` needs `recruitment.manage` and `employees.manage` and an accepted offer. It creates the employee through `EmployeeService` and, optionally, starts onboarding from a template.

## Onboarding

- **Templates:** each template holds tasks with a stage, an assignee (`hr`, `manager`, `employee` or a named `user`), a due date as a number of days from the start date, and optionally a document to collect.
- **Country documents:** `GET /onboarding/document-requirements?legal_entity_id=` returns the approved `compliance` country rule requirements for the entity's country and subdivision. Nothing country-specific is hard-coded.
- **Document review:** the employee uploads, and HR accepts it or returns it with a note. A plan completes when every task is completed or skipped.
- **Reminders:** `wingora:onboarding:reminders` runs daily at 13:00 and emails tasks due within 2 days or overdue. The task is claimed with a conditional update first, so it sends at most one reminder per task per day even if runs overlap. Dates are compared in UTC.

## AI (advisory only)

- **Provider:** the `App\Ai\LanguageModel` contract. `AI_PROVIDER=none` is the default and leaves AI unavailable; `openai_compatible` reads its URL, key and model from the environment. AI is off unless the tenant turns on `ai_assist_enabled`.
- **Summary:** `POST .../ai/summary` sends only the role, its requirements, the cover letter with names, emails and phone numbers replaced, and the document types. The system prompt forbids scoring, ranking, recommending or inferring personal characteristics. The result is stored as an `ai_drafts` row with status `pending_review`, and a person must accept or discard it.
- **Document sorting:** `POST .../ai/organize-documents` uses filename rules to set `suggested_kind` only. A person applies the suggestion.
- **Hard limit:** no AI path changes a stage, status, version, rating, offer or hire. A test checks this.
- **Audit:** every draft and review is audited with the model name, token counts and latency.
