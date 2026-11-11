# Billing and subscriptions

Wingora sells plans per organization (tenant). Stripe handles payment; Wingora never receives, stores or logs card
details (not even the last four digits). Card entry, payment methods and invoice PDFs stay on Stripe's hosted
Checkout, Customer Portal and invoice pages.

## Data

| Table | Purpose |
| --- | --- |
| `plans` | Platform-level catalog: employee limit (null = unlimited), monthly AI request allowance, included modules (`features`), trial days, rank, and per-currency monthly/annual prices with their Stripe price ids. Amounts are minor units and for display only; Stripe prices are what customers are charged. |
| `subscriptions` | Exactly one per organization (unique `tenant_id`). Status, provider (`trial`, `manual`, `stripe`), interval, currency, trial end, period end, cancellation, past-due start, scheduled downgrade, Stripe ids, `stripe_synced_at` (the `created` time of the last applied Stripe event) and an optimistic-lock `version`. |
| `invoices` | Mirrored from Stripe invoice webhooks. Hosted invoice links are redacted from the audit trail. |
| `billing_events` | One row per verified webhook event id (unique per provider). Only ids, type and outcome; never the payload. |
| `usage_counters` | Monthly counters per organization (currently `ai_requests`). |

The migration seeds Starter, Growth and Enterprise with limits and modules but **no prices**; platform staff set
prices and Stripe price ids (`PATCH /api/v1/platform/plans/{id}`). Organizations that existed before billing are given
a manually managed, active Enterprise subscription so their access does not change.

Currency follows the organization's country: US → USD, AE → AED, CA → CAD, MX → MXN (`config/billing.php`).

## Access rules

Evaluated on every organization API request by `EnforceSubscription` (`Subscription::access()`):

| State | Access |
| --- | --- |
| Trialing, trial not ended | Full |
| Active (Stripe), or manual with no end date / end date in the future | Full |
| Past due, within the grace period (`BILLING_GRACE_DAYS`, default 7) after the first failed payment | Full, with `access.reason = payment_failed` for a banner |
| Trial ended (`trial_expired`), grace period over or Stripe `unpaid` (`payment_overdue`), cancelled after the paid period (`canceled`), manual term ended (`term_ended`) | **Read-only**: reads succeed; any change returns `402` with `code: subscription_inactive` and `access_reason` |
| Organization suspended by the platform (`tenants.status = suspended`) | No sign-in at all (existing `EnsureAccountIsActive`) |

`/api/v1/billing/*` and `/api/v1/account/*` are always reachable so an owner can pay, export data or close the
account. Nothing is deleted because a subscription lapses.

## Entitlements (server-side)

- **Modules:** `App\Billing\Feature` maps each plan-gated module to its API path prefixes. A request to a module the
  effective plan does not include returns `402` `feature_not_in_plan` with `feature`, for reads and writes. Core HR
  (organization, people, departments, holidays, audit) is in every plan. The web app hides these modules from the
  menu using `billing.features` from `/auth/me`, but the server is the boundary.
- **Employees:** creating, importing or reactivating an employee beyond the plan's limit returns `402`
  `employee_limit_reached`. The check runs inside the employee transaction under a lock on the subscription row, so
  concurrent adds cannot exceed the limit. Terminated employees do not count.
- **AI:** every model call goes through `Entitlements::meterAi`, which reserves one request against the monthly
  allowance under a row lock before calling the provider and releases it if the call fails. Plans with an allowance
  of 0 return `feature_not_in_plan`; over the allowance returns `ai_limit_reached`.

## Plan changes

- **Checkout** (`POST billing/checkout`): creates a Stripe customer if needed and a hosted Checkout session for the
  chosen plan/interval. Remaining trial time is kept when at least 48 hours are left (Stripe's minimum).
- **Upgrade** (higher rank, or monthly → annual on the same plan): applied immediately; Stripe invoices the prorated
  difference (`proration_behavior=always_invoice`).
- **Downgrade**: refused if the organization has more employees than the new plan allows. Otherwise the Stripe price
  is switched without proration, so the lower price applies from the next renewal, and Wingora keeps the current
  plan's entitlements until `plan_change_at` (the period end).
- **Cancel**: Stripe subscriptions stop renewing at the period end (`cancel_at_period_end`) and can be resumed until
  then. Cancelling a free trial ends it now; it can be resumed while trial time remains.
- **Manual** subscriptions (invoiced customers) are changed only by platform staff.
- Mutations use the subscription `version`; a stale version returns `409`.

## Webhooks

`POST /api/v1/webhooks/stripe` (no session or token, own rate limit):

1. The `Stripe-Signature` header is verified against the raw body (HMAC-SHA256 of `t.payload` with
   `STRIPE_WEBHOOK_SECRET`, constant-time comparison). Timestamps older than `STRIPE_WEBHOOK_TOLERANCE` seconds
   (default 300) are rejected as replays. Failures return `400` and record a `billing.webhook_rejected` security event.
2. The event id is inserted into `billing_events` (unique) in the same transaction as its effects. A repeat delivery,
   including a concurrent one, is acknowledged with `duplicate: true` and not applied. If applying fails, the
   transaction rolls back and Stripe retries.
3. Events older than the last one applied to a subscription or invoice are ignored (`stale event`), so out-of-order
   delivery cannot overwrite newer state.
4. A subscription event is only matched to the organization whose Stripe customer it belongs to; another customer's
   subscription is ignored.

Handled: `checkout.session.completed`, `customer.subscription.*`, `invoice.*` (payment failures start the grace
period; payment status is the subscription status plus the invoice status). Other types are recorded as ignored.

## API

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/api/v1/billing/subscription` | `billing.view` |
| GET | `/api/v1/billing/plans` | `billing.view` |
| GET | `/api/v1/billing/invoices?status=&per_page=` | `billing.view` |
| POST | `/api/v1/billing/checkout` `{plan, interval}` → `{url}` | `billing.manage` |
| POST | `/api/v1/billing/change` `{plan, interval, version}` | `billing.manage` |
| POST | `/api/v1/billing/cancel`, `/api/v1/billing/resume` | `billing.manage` |
| POST | `/api/v1/billing/portal` → `{url}` | `billing.manage` |
| GET/POST/PATCH | `/api/v1/platform/plans[/{id}]` | `platform.billing.view` / `platform.billing.manage` |
| POST | `/api/v1/webhooks/stripe` | Stripe signature |

`billing.*` permissions belong to the Organization Owner only. Platform Owners manage plans; Platform
Administrators can view them. Billing restrictions are `402 application/problem+json` with a `code`.

## Configuration

`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` (per environment; test-mode keys outside production), `BILLING_TRIAL_PLAN`
(default `growth`), `BILLING_GRACE_DAYS`. Without Stripe keys, trials and manual plans work and online payment
endpoints return `503`.

## Audit

Category `billing`: subscription and invoice changes (`billing.subscription_*`, `billing.invoice_*`), checkout,
plan changes and scheduled changes, cancellation and resumption, payment failed/succeeded, and platform plan edits
(`platform.plan_*`, platform chain).
