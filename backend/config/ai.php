<?php

/*
 * AI provider for advisory features. With no provider configured every AI feature is unavailable and
 * the product works without it. Keys come from the environment only.
 */
return [
    'provider' => env('AI_PROVIDER', 'none'), // none | openai_compatible
    'openai_compatible' => [
        'base_url' => env('AI_BASE_URL'),
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL'),
        'timeout' => (int) env('AI_TIMEOUT', 20),
    ],
];
