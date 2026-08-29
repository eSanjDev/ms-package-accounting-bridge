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
    | Refresh Token Endpoint
    |--------------------------------------------------------------------------
    |
    | Path (relative to base_url) used to exchange a refresh token for a fresh
    | access token. With Laravel Passport this is the standard OAuth token
    | endpoint called with grant_type=refresh_token — NOT a separate route.
    |
    */
    'refresh_token_path' => env('ACCOUNTING_BRIDGE_REFRESH_PATH', '/oauth/token'),

    /*
    |--------------------------------------------------------------------------
    | Revoke Token Endpoint
    |--------------------------------------------------------------------------
    |
    | Path (relative to base_url) that revokes a token server-side on logout,
    | per RFC 7009. Left empty by default because Passport ships no such route:
    | pointing this at an endpoint that does not exist would make every logout
    | look like it revoked something when it did not.
    |
    */
    'revoke_token_path' => env('ACCOUNTING_BRIDGE_REVOKE_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Refresh Buffer (seconds)
    |--------------------------------------------------------------------------
    |
    | Refresh the access token this many seconds *before* it actually expires,
    | so a request never goes out with an already-dead token. Keep it well below
    | the access token's own lifetime, otherwise every request triggers a refresh.
    |
    */
    'refresh_buffer_seconds' => (int) env('ACCOUNTING_BRIDGE_REFRESH_BUFFER', 60),

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
    | OAuth Scope
    |--------------------------------------------------------------------------
    |
    | Space-separated scopes to request during the Authorization Code flow, e.g.
    | 'profile accounting:read'. Left empty the parameter is omitted entirely and
    | the OAuth server applies its own default — which is not the same as asking
    | for an empty scope.
    |
    */
    'scope' => env('ACCOUNTING_BRIDGE_SCOPE', ''),

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


    /*
    |--------------------------------------------------------------------------
    | JWT Audience & Issuer
    |--------------------------------------------------------------------------
    |
    | A valid signature only proves a token came from the OAuth server — not that
    | it was issued for *this* client. The audience defaults to your own
    | client_id; set a comma-separated list here only if you genuinely need to
    | accept tokens issued to other clients on the same server. The issuer check
    | is skipped while expected_issuer is empty.
    |
    */
    'expected_audiences' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ACCOUNTING_BRIDGE_EXPECTED_AUDIENCE', ''))
    ))),

    'expected_issuer' => env('ACCOUNTING_BRIDGE_EXPECTED_ISSUER'),


    /*
    |--------------------------------------------------------------------------
    | Redirect On Failed Login
    |--------------------------------------------------------------------------
    |
    | Send the user back through the login flow when the callback fails, instead
    | of rendering an error page. Off by default: when the cause is persistent —
    | a session cookie that never survives the callback, wrong client
    | credentials — this turns a visible error into an endless redirect loop.
    |
    */
    'redirect_on_failed_login' => (bool) env('ACCOUNTING_BRIDGE_REDIRECT_ON_FAILED_LOGIN', false),


    /*
    |--------------------------------------------------------------------------
    | Session Keys
    |--------------------------------------------------------------------------
    |
    | These keys are used to store the OAuth state and access token in the
    | session during the authentication bridge process.
    |
    */
    'session_state_key' => 'auth_bridge_state',
    'session_token_key' => 'auth_bridge',
];
