<?php

$corsEnv = static function (string $key, string $default = ''): string {
    $value = env($key);

    if (! is_string($value) || trim($value) === '') {
        return $default;
    }

    return rtrim(trim($value), '/');
};

$fromList = array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env('FRONTEND_URLS', '')),
));

$frontendOrigins = array_values(array_unique(array_filter([
    ...$fromList,
    $corsEnv('FRONTEND_URL', 'http://localhost:5173'),
    $corsEnv('ADMIN_FRONTEND_URL', 'http://localhost:5174'),
    // Common local Vite origins (localhost vs 127.0.0.1 are different CORS origins)
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:4173',
    'http://127.0.0.1:4173',
])));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $frontendOrigins,

    'allowed_origins_patterns' => [
        '/^https:\/\/[\w.-]+\.vercel\.app$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Token auth uses Authorization header; no cookies required for API calls.
    'supports_credentials' => false,

];
