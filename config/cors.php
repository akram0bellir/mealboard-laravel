<?php

// The desktop door app calls /api from its own address. Login uses the Authorization header, never cookies,
// so allowing other origins is safe (same choice as the PHP API's 'cors_origins' => '*').
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('MEALBOARD_CORS_ORIGINS', '*')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
