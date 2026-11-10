<?php

return [
    // HMAC key for the audit hash chains. Set a dedicated secret per environment; falls back to a key derived from APP_KEY.
    'hmac_key' => env('AUDIT_HMAC_KEY'),

    /*
     * Retention in days. These are configurable defaults, not legal advice: confirm the periods your
     * jurisdictions and contracts require. Tenants may choose values between minimum_days and maximum_days.
     */
    'retention' => [
        'minimum_days' => (int) env('AUDIT_RETENTION_MIN_DAYS', 365),
        'maximum_days' => (int) env('AUDIT_RETENTION_MAX_DAYS', 3650),
        'default_days' => (int) env('AUDIT_RETENTION_DEFAULT_DAYS', 2555),
        'categories' => [
            'authentication' => 730,
            'api_tokens' => 730,
            'authorization' => 365,
            'payroll' => 2555,
            'employee_profile' => 2555,
            'employee_documents' => 2555,
        ],
    ],

    'export' => [
        'max_rows' => (int) env('AUDIT_EXPORT_MAX_ROWS', 50000),
    ],
];
