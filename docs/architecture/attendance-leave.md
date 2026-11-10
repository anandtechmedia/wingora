# Attendance and leave

## Time model
- Instants (clock in/out and scheduled start/end) are stored in UTC. `work_date` and all leave dates are local calendar dates, stored as `Y-m-d` (`App\Casts\LocalDate`).
- Employee zone: `employees.timezone`, else the work location's `timezone`, else UTC (`App\Time\TimeZones`). The work date is the local date at clock-in.
- Shifts are wall-clock times. `ScheduleResolver::window()` places them on the local calendar before converting, so a 22:00–06:00 shift lasts 7 h on spring-forward and 9 h on fall-back nights.
- Client local times (`Y-m-d H:i`) that fall in a DST gap are rejected with 422. Repeated fall-back times resolve to the first occurrence.

## Attendance
- **Shifts:** start/end, break, late/early grace, and an optional overtime threshold (default: scheduled minutes minus the break).
- **Schedules:** `work_schedules` map ISO weekday → shift (null = rest day), and one can be the default. With no schedule, Monday–Friday are working days.
- **Assignments:** `shift_assignments` are effective-dated per employee. A new open-ended assignment closes the previous one, and overlaps are rejected.
- **Clock in/out:** `open_employee_id` is unique, so only one open record per employee can exist, and `(employee_id, work_date)` is unique. The employee row is locked during clock-in. Duplicates return 409.
- **Corrections:** `PUT /attendance/records` requires `attendance.manage` and is never allowed on one's own record. It is audited as `attendance.adjusted` with before/after values and the reason. Approved requests are audited as `attendance.adjustment_approved`.
- **Report:** per employee, `GET /attendance/report` returns:
  - days present, and days absent (working days that are not holidays, not on approved leave, and before today);
  - late and early counts and minutes, worked minutes and overtime minutes.

  It covers at most 92 days. The CSV export is formula-safe and audited.

## Leave
- **Types:** `leave_types` can be tied to a country and to a key from the approved country leave rules (`RuleCategory::Leave`). Statutory entitlements are never hard-coded.
- **Policies:** `leave_policies` are effective-dated per type, optionally per legal entity (the entity policy wins), and may not overlap. Settings:
  - entitlement and accrual (`annual` with optional proration for new joiners, `monthly`, or `none`);
  - carry-forward cap and expiry;
  - whether the balance may go negative, maximum consecutive days and minimum notice.
- **Ledger:** `leave_ledger_entries` are append-only, with types allocation, accrual, carry_forward, carry_out, lapse, deduction, reversal and adjustment.
  - Runs are idempotent through `idempotency_key`.
  - Available = sum of entries − unused expired carry-forward − pending requests.
- **Requests:**
  - Only working days in the employee's schedule count, minus the location's public and company holidays.
  - A request may not overlap another or span two calendar years.
  - It needs enough balance unless the policy allows going negative. Half days apply to a single day only.
- **Approvals:**
  - Step 1 is the direct or department manager (`leave.approve`) or HR (`leave.manage`).
  - Step 2, when `approval_steps = 2`, is HR, by someone other than the step-1 approver.
  - Nobody decides their own request.
  - The request row is locked and its status re-checked, and `(request, step)` is unique, so a concurrent second decision returns 409. The deduction is written once, on final approval.
- **Cancellation:**
  - Pending requests: the employee or HR.
  - Approved requests: the employee before the leave starts, or HR at any time. The days come back as a reversal entry.
- **Carry-forward:** runs only for completed years. It moves up to the next year's policy cap, with the expiry if one is set; the rest lapses.

## Permissions
| Permission | Employee | Dept manager | HR manager | HR admin |
|---|---|---|---|---|
| attendance.view/clock, leave.view/apply | ✓ | ✓ | ✓ | ✓ |
| attendance.approve, leave.approve (own reports and departments) | | ✓ | ✓ | ✓ |
| attendance.manage, leave.manage (whole organization) | | | ✓ | ✓ |
| attendance.configure, leave.configure | | | | ✓ |

Payroll administrators get the self-service permissions. Organization owners get every permission. Items outside a user's scope return 404.

## Endpoints (`/api/v1`)
- **Attendance:** `attendance/me`, `attendance/clock-in`, `attendance/clock-out`, `attendance/records` (GET, PUT), `attendance/report`.
- **Attendance requests:** `attendance/requests` (GET, POST), plus `/{id}/approve|reject|cancel`.
- **Shifts and schedules:** `shifts`, `work-schedules`, `employees/{id}/schedule-assignments`.
- **Leave setup:** `leave-types`, plus `/{id}/policies|allocate|carry-forward`.
- **Leave balances and holidays:** `leave/balances`, `leave/ledger`, `leave/adjustments`, `leave/holidays`.
- **Leave requests:** `leave/requests` (GET, POST, `/preview`, `/{id}`), plus `/{id}/approve|reject|cancel`.

Rejections require a `note`. Request lists accept `scope=mine|approvals|all`.
