# Projects and timesheets

Client and internal projects, task assignments, weekly employee timesheets with approval, and a per-project hours report.

## Access

| Permission | Who has it | What it allows |
|---|---|---|
| `projects.view` | Every organization role | Projects the caller manages or belongs to, their own tasks, their own weekly timesheet |
| `projects.manage` | Organization Owner, HR Administrator, HR Manager | Every project: create, edit, members, tasks, report |
| `timesheets.approve` | Owner, HR Administrator, HR Manager, Department Manager | Approve or return submitted timesheets |

A project's manager can edit it, its members and its tasks, and see its report, without `projects.manage`. Task assignees can change only the status of their own tasks. Department Managers approve only their direct reports; HR approves anyone; nobody approves their own timesheet.

All routes are tenant-scoped through `TenantModel` and server-side checks. The `projects` feature is included in Growth and Enterprise; Starter gets `402`.

## Data model

- `projects`: unique `code` per tenant, name, client, description, `status` (`planned`, `active`, `on_hold`, `completed`, `cancelled`), start/end dates, `budget_hours`, manager.
- `project_members`: employees on a project (the manager is always a member).
- `project_tasks`: title, description, `status` (`todo`, `in_progress`, `done`), assignee (must be a member), due date, estimate.
- `timesheets`: one per employee per week (`week_start` is a Monday), `status` (`draft`, `submitted`, `approved`, `rejected`), total minutes, submit and decision metadata.
- `timesheet_entries`: project, optional task, work date, minutes, note. Entries carry `employee_id` so reports need no joins.

All tenant references use composite `(id, tenant_id)` foreign keys. Every write takes the record's `version`; stale writes get `409`.

## Workflow rules

- Time can only be logged to `active` projects the employee belongs to or manages, on dates inside the week, with at most 24 hours a day. A task must belong to the entry's project.
- Saving replaces the week's entries as a set. Draft and returned (`rejected`) weeks can be edited; submitted and approved weeks cannot.
- Submitting needs at least some time. Returning needs a note, which the employee sees.
- Tasks cannot be added or edited on `completed` or `cancelled` projects. Members with open tasks cannot be removed.
- The report sums approved and submitted (pending) time by person, task and week, with budget usage when a budget is set.

## API (`/api/v1`)

| Method | Path | Notes |
|---|---|---|
| GET, POST | `projects` | Search `q`, filter `status`; paginated |
| GET, PATCH | `projects/{project}` | Includes members |
| POST | `projects/{project}/members` | `employee_id` |
| DELETE | `projects/{project}/members/{employee}` | `422` while they have open tasks |
| GET | `projects/{project}/report` | Optional `from`, `to` |
| GET, POST | `projects/{project}/tasks` | Paginated |
| GET | `projects/tasks/mine` | Caller's open tasks |
| PATCH | `projects/tasks/{task}` | Assignees may send only `status` |
| GET | `timesheets` | HR list; filter `status`, `employee`; paginated |
| GET, PUT | `timesheets/mine/{week}` | Week plus loggable projects; PUT takes `entries[]` and `version` |
| GET | `timesheets/approvals` | Submitted timesheets the caller may decide |
| GET | `timesheets/{timesheet}` | Owner, approver or HR |
| POST | `timesheets/{timesheet}/submit` | `version` |
| POST | `timesheets/{timesheet}/approve`, `.../reject` | `version`, `note` (required to reject) |

## Audit

Model events are filed under `projects.*` (category `projects`): project, member, task and timesheet changes, including submit and decisions. Individual entry rows are not audited; the timesheet's total-minute change is.

## Web

`apps/web/src/modules/projects` (lazy-loaded) uses `ProjectsSource` from `apps/web/src/api/projects.ts`. Tabs: My timesheet, My tasks, Projects, Approvals (shown only with `timesheets.approve`).
