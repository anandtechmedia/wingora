<?php

/*
 * SaaS billing. Card details never reach Wingora: checkout, payment methods and invoice PDFs stay on Stripe's
 * hosted pages. Keys come from the environment only. Access rules are documented in docs/architecture/billing.md.
 */
return [
    // Plan code new organizations start their free trial on.
    'trial_plan' => env('BILLING_TRIAL_PLAN', 'growth'),

    // Days of full access after a renewal payment fails, before the organization becomes read-only.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    // Organization country => billing currency.
    'currencies' => ['US' => 'USD', 'AE' => 'AED', 'CA' => 'CAD', 'MX' => 'MXN'],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com/v1'),
        // Signed webhook timestamps older than this many seconds are rejected (replay protection).
        'tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        'timeout' => (int) env('STRIPE_TIMEOUT', 15),
    ],
];
