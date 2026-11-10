# Payroll preparation

Wingora prepares payroll for review and approval, then hands it to a payroll provider. **It is not a statutory payroll
engine.** It never calculates income tax, social security or any other statutory item, and it makes no claim of
compliance with any country's payroll law. Tax and statutory amounts appear only when payroll staff enter them or a
provider supplies them.

## Where every figure comes from

Every payroll line has a `source`, shown in the API, on screen, in exports and on the payslip PDF:

| source | Meaning | Examples |
| --- | --- | --- |
| `calculated` | Worked out by Wingora from configuration it owns | base salary, `fixed`/`percent_of_base` components, loan instalments, approved reimbursements |
| `manual` | Entered by payroll staff; a reason is required and audited | statutory amounts from a worksheet, one-off bonuses |
| `provider` | Imported from a payroll provider, with its reference | income tax, CPP/EI, IMSS, WPS figures |

## Money

- Integer minor units (cents) in the legal entity's currency (USD, AED, CAD, MXN), never floats (`App\Payroll\Money`).
- The API returns `{ "amount": "1234.50", "minor": 123450, "currency": "USD" }`.
- Each line is rounded on its own, half away from zero. Totals are exact sums of the rounded lines.
- A run has one currency. Compensation, loans or reimbursements in another currency are flagged as
  `currency_mismatch` and block submission.

## Calculation rules (`App\Payroll\PayrollCalculator`)

These are arithmetic conventions, not legal rules.

1. **Full-period base:** `annual` ÷ periods per year (weekly 52, biweekly 26, semimonthly 24, monthly 12);
   `monthly` × 12 ÷ periods per year; `period` as is (annualised first if set for another frequency).
2. **Proration by calendar days:** each compensation record overlapping the period and the employment window
   (`hire_date`–`termination_date`) gives one `BASE` line: full-period amount × days covered ÷ days in period.
   A mid-period raise gives two lines.
3. **Structure components** apply per structure, only to the days covered by records that carry it (components
   effective on the last such day): `fixed` is prorated by those days; `percent_of_base` is the rate × the base of those
   days. A structure assigned on the 17th of a 31-day month pays a 500.00 fixed allowance as 241.94 (15/31).
   `manual`/`provider` components raise an `awaiting_input` issue while no entered or provider line with that code
   exists; removing that line brings the issue back.
4. **Loans:** `min(instalment, outstanding)`, no interest. The repayment is recorded on final approval; the loan
   closes at zero.
5. **Reimbursements:** approved, unpaid items in the run currency become earnings and are marked `paid` on final
   approval.
6. **Off-cycle runs** carry only entered and provider lines (no base, loans or structure lines).
7. **Recalculation** replaces only `calculated` lines.
8. **Negative net pay** is flagged `negative_net` and blocks submission.

## Country configuration

- Pay calendars belong to a legal entity, which sets the country and currency.
- The pay frequency must be in the country's approved, effective `payroll` rule (`pay_frequencies`). With no approved
  rule, the calendar is refused.
- Components may reference a rule `statutory_items` key only with the `manual` or `provider` method. Wingora holds no
  rates.

## Lifecycle and approvals (`App\Payroll\PayrollWorkflow`)

`draft → in_review → approved → published`

- Rejection (note required) returns to `draft`; the next submission starts a new review cycle.
- 1–3 approval levels per pay calendar. Approvers need `payroll.approve`. The preparer and submitter can never approve,
  and each level in a cycle needs a different person (unique key on run, cycle, approver).
- Every transition locks the run row (`lockForUpdate`), so simultaneous approvals produce one decision; the other gets
  `409`/`403`.
- Approved and published runs are immutable; corrections use an off-cycle run. One regular run per period (unique
  `regular_period_id`).

## Permissions

| Permission | Payroll Admin | Org Owner | Dept Manager | Employee / HR roles | Auditor |
| --- | --- | --- | --- | --- | --- |
| `payroll.view`, `payroll.manage`, `payroll.configure`, `payroll.export` | yes | yes | – | – | – |
| `payroll.approve` (never own run) | yes | yes | – | – | – |
| `reimbursements.approve` (reports and managed departments) | yes | yes | yes | – | – |
| `payslips.view.self`, `reimbursements.submit` | yes | yes | yes | yes | – |

HR roles and Auditors see no payroll amounts (Auditors still see payroll audit events). Employees see their own
payslips only after publication. Other tenants' records return `404`.

## Payslips, receipts and files

- `GET /payroll/payslips/{id}/pdf` renders on request with `App\Payroll\Pdf` (built in, no dependency). Nothing is
  stored. Lines show their source, the footer states it is not a statutory calculation, and the response is
  `Cache-Control: no-store`. Every view is audited (`payroll.payslip_viewed`).
- Receipts are uploaded via `/files` and attached with `receipt_id`. They then move into the private `payroll`
  category. Approvers download them only via `GET /reimbursements/{id}/receipt`, which checks access and is audited.

## Provider integration

- `App\Payroll\Providers\PayrollProvider`: `export(run)` and `parseResults(content)`, registered in
  `config/payroll.php`.
- Built-in `generic_csv` exports every line and imports `employee_number,code,name,kind,amount,reference`.
- Imports are all-or-nothing and replace earlier provider lines.
- Real adapters implement the same interface. Credentials belong in environment configuration.

## Audit events

`payroll.compensation_changed` (amounts redacted), `payroll.compensation_viewed`/`_listed`, `payroll.run_created`,
`run_prepared`, `run_submitted`, `run_approved` (level, final), `run_rejected`, `run_published`, `line_entered`,
`line_removed`, `provider_imported`, `exported` (plus `export.completed`), `payslip_viewed`, `loan_created`,
`loan_cancelled`, `reimbursement_submitted`, `reimbursement_decided`.

All are in the payroll audit category and retention. Configuration models are recorded as
`payroll.<model>_<action>`.

## Compensation history

- `compensation_records` is effective-dated. A new record closes the open one the day before.
- Records cannot be inserted before a later one; corrections are new records with a reason.
- The original `employee_compensations` row (`GET /employees/{id}/compensation`) is kept in step with today's record,
  as annual-equivalent `base_salary`.

## API (`/api/v1`)

| Endpoint | Permission |
| --- | --- |
| `GET payroll/providers`, `payroll/calendars`, `payroll/periods`, `payroll/structures` | `payroll.view` |
| `POST payroll/calendars`, `PATCH payroll/calendars/{id}`, `POST payroll/calendars/{id}/periods` | `payroll.configure` |
| `POST payroll/structures`, `PATCH payroll/structures/{id}`, `POST …/components`, `POST …/components/{id}/end` | `payroll.configure` |
| `GET payroll/compensation`, `GET employees/{id}/compensation-history` | `payroll.view` |
| `POST employees/{id}/compensation-history` | `payroll.manage` |
| `GET payroll/runs`, `GET payroll/runs/{id}`, `GET payroll/runs/{id}/payslips` | `payroll.view` |
| `POST payroll/runs`, `…/prepare`, `…/payslips/{id}/lines`, `DELETE …/lines/{id}`, `…/provider-import`, `…/submit`, `…/publish` | `payroll.manage` |
| `POST payroll/runs/{id}/approve`, `…/reject` | `payroll.approve`, not preparer or submitter |
| `GET payroll/runs/{id}/export?format=summary\|register\|provider` | `payroll.export` |
| `GET payroll/payslips/mine`, `payroll/payslips/{id}`, `payroll/payslips/{id}/pdf` | own published payslip, or `payroll.view` |
| `GET/POST payroll/loans`, `POST payroll/loans/{id}/cancel` | own (`payslips.view.self`) or `payroll.view`/`manage` |
| `GET/POST reimbursements`, `POST …/{id}/approve\|reject\|cancel`, `GET …/{id}/receipt` | `reimbursements.submit`/`approve`, `payroll.manage` |

Lists are paginated (`per_page` ≤ 100). Errors use problem+json: `403` forbidden, `404` not found or another tenant,
`409` wrong state, `422` validation.
