# Helpdesk and knowledge base

Additive migration `2026_12_01_000100_create_helpdesk_tables`. Every table is tenant-scoped (`tenant_id` plus the `BelongsToTenant` global scope); child rows use composite `(id, tenant_id)` foreign keys so they cannot point at another tenant's parent.

## Permissions

| Permission | Granted to | Allows |
| --- | --- | --- |
| `helpdesk.submit` | HR Admin, HR Manager, Department Manager, Employee, Payroll Admin | file tickets, see and reply to your own, read published FAQs and articles |
| `helpdesk.agent` | HR Admin, HR Manager | work the queue: take or release tickets, change status and priority, internal notes |
| `helpdesk.manage` | HR Admin, HR Manager | everything an agent can do, assign to anyone eligible, write and publish knowledge-base content |
| `helpdesk.configure` | HR Admin | categories, restricted-category agent lists, due-by targets |

The Organization Owner holds every tenant permission. Auditors get none of these.

## Visibility rules (server-side, `App\Helpdesk\HelpdeskAccess`)

- A requester always sees their own tickets, but never internal notes or internal attachments.
- Agents and helpdesk managers see every ticket in **unrestricted** categories.
- **Restricted** categories (e.g. workplace conduct) are visible only to the agents listed on the category. Holding `helpdesk.manage` or `helpdesk.configure` does not bypass the list. A ticket can only be assigned to someone who could see it.
- Anything outside these rules returns `404`, so ticket IDs cannot be probed.

## Data protection

- Ticket descriptions and comment bodies are encrypted at rest (`encrypted` cast). Subjects stay plain for search; audit entries redact them.
- Attachments are stored with `PrivateUpload` in the `helpdesk` file category (allow-listed types, content must match the extension). The generic `/files/{id}/download` refuses that category; downloads go through `/helpdesk/attachments/{id}/download`, which rechecks ticket visibility and the internal flag and writes `helpdesk.attachment_viewed`.

## Status flow

| From | Staff may move to | Requester may move to |
| --- | --- | --- |
| `open` | `in_progress`, `waiting`, `resolved` | `closed` (withdraw) |
| `in_progress` | `waiting`, `resolved` | `closed` |
| `waiting` | `in_progress`, `resolved` | `closed` |
| `resolved` | `open` (reopen), `closed` | `open`, `closed` |
| `closed` | — | — |

A public reply from the requester moves a `waiting` ticket back to `in_progress`. The first public staff reply sets `first_response_at`. Every ticket response carries `next`, the transitions the current user may make, and `can` flags; the UI only hides what the server would refuse.

**Concurrency:** status, assignment and priority/category changes require the ticket's `version`. The row is locked, the version compared, and a mismatch returns `409`, so two agents cannot overwrite each other.

**Due-by targets:** each tenant sets hours per priority (`low`, `normal`, `high`, `urgent`; null = no target). Defaults are 120/72/24/8 hours. `due_at` is computed from the ticket's creation time and recomputed when the priority changes. These are internal targets only; Wingora implies no contractual or legal service level.

**Numbers:** tickets are numbered per tenant (`#1`, `#2`, …) from a counter on `helpdesk_settings`, taken under a row lock.

## Endpoints (`/api/v1`, Sanctum + MFA + tenant)

| Method | Path | Notes |
| --- | --- | --- |
| GET/PATCH | `helpdesk/settings` | configure; `{due_hours: {low, normal, high, urgent}}` |
| GET | `helpdesk/categories` | submitters see active ones; configurators also see inactive ones and `agents` |
| POST/PATCH | `helpdesk/categories[/{id}]` | `name`, `description`, `restricted`, `active`, `sort_order`, `agent_ids[]` |
| GET | `helpdesk/agents` | staff or configure; users who can work tickets |
| GET | `helpdesk/tickets` | `scope=mine|assigned|queue`, `status` (or `active`), `priority`, `category_id`, `q` (subject or `#number`), `overdue`, `per_page`; paginated |
| POST | `helpdesk/tickets` | multipart: `category_id`, `subject`, `description`, `priority`, `files[]` (max 5) |
| GET/PATCH | `helpdesk/tickets/{id}` | detail with comments and attachments filtered for the viewer; PATCH (staff): `priority`, `category_id`, `version` |
| POST | `helpdesk/tickets/{id}/assign` | `{assignee_id|null, version}`; agents may only take or release |
| POST | `helpdesk/tickets/{id}/status` | `{status, version}` |
| POST | `helpdesk/tickets/{id}/comments` | multipart: `body`, `internal` (staff only), `files[]` |
| GET | `helpdesk/attachments/{id}/download` | audited |
| GET | `helpdesk/articles` | `kind=faq|article`, `q`, `category_id`, `status` (managers); FAQs include `body` in the list |
| GET | `helpdesk/articles/{id}` | drafts are 404 for non-managers |
| POST/PATCH/DELETE | `helpdesk/articles[/{id}]` | manage |
| POST | `helpdesk/articles/{id}/publish` · `/unpublish` | manage; publish is audited |

## Audit

Events land in the `helpdesk` audit category: `helpdesk.ticket_opened`, `helpdesk.ticket_assigned`, `helpdesk.ticket_status_changed`, `helpdesk.comment_added` (records whether it was internal and the attachment count, never the body), `helpdesk.attachment_viewed`, `helpdesk.settings_changed`, `helpdesk.article_published`, plus model-level create/update events with encrypted and redacted fields shown as `[REDACTED]`.
