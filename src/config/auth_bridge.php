<?php

declare(strict_types=1);

$prefix = env('ACCOUNTING_BRIDGE_ROUTE_PREFIX', 'accounting');
$callbackPath = env('ACCOUNTING_BRIDGE_PATH_CALLBACK', 'callback');
$defaultRedirectUrl = rtrim(env('APP_URL', ''), '/') . '/' . trim($prefix, '/') . '/' . trim($callbackPath, '/');

return [
    /*
    |--------------------------------------------------------------------------
    | OAuth Client Credentials
    |--------------------------------------------------------------------------
    |
    | These are the client credentials provided by the OAuth server.
    |
    */
    'client_id' => env('ACCOUNTING_BRIDGE_CLIENT_ID'),
    'client_secret' => env('ACCOUNTING_BRIDGE_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Server URL
    |--------------------------------------------------------------------------
    |
    | The base URL of the OAuth authorization server.
    |
    */
    'base_url' => env('ACCOUNTING_BRIDGE_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Redirect URL Configuration
    |--------------------------------------------------------------------------
    |
    | The default redirect URL for OAuth callbacks.
    | This can be overridden at runtime by passing callback_url query parameter.
    |
    | Examples:
    | - Default: Uses this configured URL
    | - Custom absolute: https://example.com/custom/callback
    | - Custom relative: /my-app/oauth/callback (will be prefixed with APP_URL)
    |
    */
    'redirect_url' => env('ACCOUNTING_BRIDGE_REDIRECT_URL', $defaultRedirectUrl),

    /*
    |--------------------------------------------------------------------------
    | OAuth Prompt
    |--------------------------------------------------------------------------
    |
    | The prompt parameter for OAuth authorization.
    | Options: none, consent, login
    |
    */
    'auth2_prompt' => env('ACCOUNTING_BRIDGE_OAUTH_PROMPT', 'consent'),

    /*
    |--------------------------------------------------------------------------
    | Success Redirect URL
    |--------------------------------------------------------------------------
    |
    | Where to redirect after successful authentication.
    | Can be overridden at runtime via success_redirect query parameter.
    |
    */
    'success_redirect' => env('ACCOUNTING_BRIDGE_SUCCESS_REDIRECT', '/'),

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the route prefix and middleware for auth bridge routes.
    |
    */
    'routes' => [
        'prefix' => $prefix,
        'middleware' => explode(',', env('ACCOUNTING_BRIDGE_MIDDLEWARE', 'web')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Paths
    |--------------------------------------------------------------------------
    |
    | The paths for redirect and callback routes.
    |
    */
    'route_path' => [
        'redirect' => env('ACCOUNTING_BRIDGE_PATH_REDIRECT', 'login'),
        'callback' => $callbackPath,
    ],


   /*
   |--------------------------------------------------------------------------
   | OAuth Public Key
   |--------------------------------------------------------------------------
   |
   | Path to the public key file for OAuth authentication.
   |
   */
    'public_key_path' => env('ACCOUNTING_BRIDGE_KEY_PATH', storage_path('oauth-public.key')),
];
