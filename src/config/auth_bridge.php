<?php

return [
    'client_id' => env('ACCOUNTING_BRIDGE_CLIENT_ID'),

    'client_secret' => env('ACCOUNTING_BRIDGE_CLIENT_SECRET'),

    'base_url' => env('ACCOUNTING_BRIDGE_BASE_URL'),

    'redirect_url' => env('ACCOUNTING_BRIDGE_REDIRECT_URL'),

    'auth2_prompt' => env('ACCOUNTING_BRIDGE_OAUTH_PROMPT', 'consent'),

    'success_redirect' => env('ACCOUNTING_BRIDGE_SUCCESS_REDIRECT', '/'),

    'routes' => [
        'prefix' => env('ACCOUNTING_BRIDGE_ROUTE_PREFIX', 'accounting'),
        'middleware' => ['web'],
    ],

    'route_path' => [
        'redirect' => env('ACCOUNTING_BRIDGE_PATH_REDIRECT', 'login'),
        'callback' => env('ACCOUNTING_BRIDGE_PATH_CALLBACK', 'callback'),
    ],
];
