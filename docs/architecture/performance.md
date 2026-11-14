# Performance management

Objectives and OKRs, review cycles, 360° feedback, one-to-ones and performance history. API under `/api/v1/performance`, web module at `/performance` (`apps/web/src/modules/performance`).

## Access

| Permission | Grants |
|---|---|
| `performance.view` | Own objectives, own reviews, own one-to-ones, feedback requested of you, own history |
| `performance.review` | The above for direct and indirect reports (reporting line, via `Approvers`) |
| `performance.manage` | The above for every employee in the organization |
| `performance.configure` | Create, edit, launch and close review cycles |

- Every query is tenant-scoped and goes through `App\Performance\PerformanceAccess`; a record outside your reach returns 404, not 403.
- Nobody decides on their own review: manager assessments use `Approvers::canDecide` (no self-approval).
- The feature is billed: `performance` is in the Growth and Enterprise plans. Other plans get HTTP 402 from the billing middleware.

## Privacy rules

- **Manager assessment and rating** are hidden from the employee until the review is `completed`.
- **Anonymous 360° feedback** never returns the reviewer, to anyone, including HR. Named feedback shows the reviewer.
- **Private one-to-one notes** are returned only to the manager in that meeting, never to the employee or to HR.
- Answers, notes and feedback are encrypted at rest; audit entries redact encrypted fields.

## Workflows

- **Objectives:** `objective` (manual progress) or `okr` (1–10 key results; progress is the weighted average of key-result progress). Statuses `draft → active → completed | cancelled`. Check-ins only on active objectives. Objectives can align to a parent; loops are rejected.
- **Review cycles:** `draft → open → closed`. Dates, rating scale (3, 4, 5 or 10) and questions (`text` or `rating`) can change only in draft. Launch creates one review per active employee, with their current manager, in one transaction.
- **Reviews:** `pending_self → pending_manager → completed → acknowledged`. Answers are validated against the cycle's questions and scale.
- **360° feedback:** managers or HR request it from anyone in the organization except the subject (one request per reviewer per review); the reviewer submits or declines while the cycle is open.
- **One-to-ones:** `scheduled → completed | cancelled`. Employees can schedule with their manager; managers and HR with their reports. Shared notes and action items are visible to both people.

All writes that change an existing record take `version` and return 409 if it is stale.

## Endpoints

| Method | Path | Notes |
|---|---|---|
| GET/POST | `/objectives` | `scope=mine\|team\|all`, `status`, `q`, `cycle_id`; paginated |
| GET/PATCH | `/objectives/{id}` | includes key results and the last 20 check-ins |
| POST | `/objectives/{id}/check-ins` | `key_result_id` + `value`, or `progress` (objectives) |
| GET/POST | `/cycles` | create needs `performance.configure` |
| PATCH | `/cycles/{id}` | draft only |
| POST | `/cycles/{id}/launch`, `/cycles/{id}/close` | |
| GET | `/reviews`, `/reviews/{id}` | `scope`, `status`, `cycle_id` |
| POST | `/reviews/{id}/self`, `/reviews/{id}/manager`, `/reviews/{id}/acknowledge` | |
| POST | `/reviews/{id}/feedback` | `reviewer_id`, `relationship`, `anonymous` |
| GET | `/feedback` | requests addressed to you |
| POST | `/feedback/{id}/submit`, `/feedback/{id}/decline` | reviewer only |
| GET/POST | `/meetings` | |
| GET/PATCH | `/meetings/{id}` | |
| GET | `/people?q=` | up to 20 active employees matching `q`, to pick an owner or reviewer |
| GET | `/history`, `/employees/{id}/history` | completed reviews, objectives and counts |

## Audit

Model changes are logged as `performance.<model>_<action>` in the `performance` audit category; cycle launches as `performance.review_cycle_launched`.

## Performance

Lists are paginated (25 per page) and eager-load employees; review counts use `withCount`. Indexes lead with `tenant_id` and the employee or status column each list filters on.
