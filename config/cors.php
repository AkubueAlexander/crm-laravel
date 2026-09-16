<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | 1.0 / 16.5: the SPA (Vite, :5173) and the API (:8000) are different
    | origins in local dev. Sanctum's SPA cookie-auth flow is a credentialed
    | cross-origin request, so `supports_credentials` must be true and
    | `allowed_origins` must be an explicit list — browsers reject the
    | combination of a wildcard origin with credentialed requests outright.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'up',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
