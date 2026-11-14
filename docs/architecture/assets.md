# Assets

Company equipment: a registry of assets grouped by category, assignment to employees, returns, maintenance records and a per-asset history.

## Access

| Permission | Who has it | What it allows |
|---|---|---|
| `assets.view` | Every organization role | `GET /assets/mine`: the caller's own assignments, current and past |
| `assets.manage` | Organization Owner, HR Administrator, HR Manager | Registry, categories, assignment, return, maintenance, history |

All routes are tenant-scoped through `TenantModel` and server-side permission checks; employees never reach the registry. The `assets` feature is included in Growth and Enterprise; Starter gets `402`.

## Data model

- `asset_categories`: unique name per tenant, `active` (inactive categories cannot take new assets). Never deleted.
- `assets`: unique `asset_tag` per tenant, category, serial number, purchase date/cost/currency, warranty end, `status` (`available`, `assigned`, `in_maintenance`, `retired`), `condition` (`new`, `good`, `fair`, `poor`, `damaged`), current employee.
- `asset_assignments`: one row per hand-out with assigned/due-back/returned dates, condition out and in, notes, who assigned and who recorded the return.
- `asset_maintenance_records`: `kind` (`repair`, `service`, `inspection`, `upgrade`), description, vendor, cost, `status` (`scheduled`, `in_progress`, `completed`, `cancelled`).

All tenant references use composite `(id, tenant_id)` foreign keys.

## Workflow rules

- Only `available` assets can be assigned; returning sets the asset back to `available` with the returned condition.
- Maintenance cannot be added to assigned or retired assets. `in_progress` work sets the asset to `in_maintenance`; completing or cancelling the last active work returns it to `available`.
- Only `available` assets can be retired; retired assets can be reinstated.
- Every write takes the record's `version`, locks the row and re-checks state inside a transaction; stale writes get `409`.

## API (`/api/v1`)

| Method | Path | Notes |
|---|---|---|
| GET | `assets/mine` | Paginated own assignments |
| GET, POST | `assets` | Search `q`, filter `status`, `category`, `employee`; paginated |
| GET, PATCH | `assets/{asset}` | PATCH also changes `status` to `retired`/`available` |
| POST | `assets/{asset}/assign` | `employee_id`, `assigned_on`, `due_back_on`, `notes`, `version` |
| POST | `assets/{asset}/return` | `condition`, `returned_on`, `notes`, `version` |
| GET | `assets/{asset}/history` | Assignments and maintenance, newest first |
| GET, POST | `assets/{asset}/maintenance` | |
| PATCH | `assets/maintenance/{record}` | `status`, `version` |
| GET, POST | `assets/categories` | |
| PATCH | `assets/categories/{category}` | |

Writes are audited under the `assets` audit category.

## Web

`apps/web/src/modules/assets`: My assets (everyone), Registry and Categories (`assets.manage`). The asset drawer holds assign, return, edit, maintenance, retire/reinstate (with confirmation) and history.

## Tests

`backend/tests/Feature/Assets/AssetsTest.php` (lifecycle, validation and concurrency, maintenance and retirement, permissions, tenant isolation and plan gate) and `apps/web/src/modules/assets/assets.test.tsx`.
