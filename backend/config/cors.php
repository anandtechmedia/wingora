<?php

// Only explicitly listed origins may call the API from a browser. "*" is
// rejected because credentialed (cookie) requests must never be wildcarded.
$origins = array_values(array_filter(
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    fn (string $origin) => $origin !== '' && $origin !== '*',
));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept', 'Accept-Language', 'Authorization', 'Content-Type',
        'Idempotency-Key', 'X-Request-Id', 'X-Requested-With', 'X-XSRF-TOKEN',
    ],
    'exposed_headers' => ['X-Request-Id', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => true,
];
