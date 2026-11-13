<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    // Comma-separated host regexes; empty means "all subdomains of APP_URL".
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),

    'login' => [
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
        'per_minute' => 5,
        'per_ip_per_minute' => 20,
    ],

    'tokens' => [
        'ttl_minutes' => (int) env('API_TOKEN_TTL_MINUTES', 10080),
        'max_per_user' => (int) env('API_TOKEN_MAX_PER_USER', 10),
        'abilities' => ['api:read', 'api:write'],
    ],

    'session' => [
        'absolute_lifetime_minutes' => (int) env('SESSION_ABSOLUTE_LIFETIME', 720),
    ],

    'mfa' => [
        'issuer' => env('MFA_ISSUER', 'Wingora'),
        'window' => 1,
        'pending_login_seconds' => 300,
        'recovery_codes' => 10,
    ],

    'files' => [
        'disk' => 'private',
        'max_kilobytes' => (int) env('SECURE_FILES_MAX_KB', 10240),
        'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'docx', 'xlsx', 'csv', 'txt'],
        'mime_types' => [
            'application/pdf', 'image/png', 'image/jpeg', 'text/plain', 'text/csv',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

    'countries' => ['US', 'AE', 'CA', 'MX'],
];
