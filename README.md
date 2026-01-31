# AuthBridge

**AuthBridge** is a comprehensive Laravel package for OAuth 2.0 authentication integration. While originally designed for the Accounting microservice, it provides a flexible and secure bridge to any OAuth 2.0 authorization server, enabling seamless authentication flows including Authorization Code Grant and Client Credentials Grant.

## Features

- Full OAuth 2.0 support (Authorization Code & Client Credentials flows)
- Secure state validation (CSRF protection)
- Event-driven architecture for flexible integration
- JWT token extraction and validation
- Token caching for Client Credentials flow
- Open Redirect protection via whitelisting
- Runtime configuration via query parameters
- Fully configurable via environment variables
- Laravel 10+ and 11+ support

## Requirements

- **PHP:** 8.1+
- **Laravel:** 10.x, 11.x, 12.x
- **OAuth Server:** Any OAuth 2.0 compliant server

## Installation

```bash
composer require esanj/auth-bridge
```

## Configuration

### 1. Publish the configuration file

```bash
php artisan vendor:publish --provider="Esanj\\AuthBridge\\AuthBridgeServiceProvider" --tag="config"
```

### 2. Environment Variables

Add these to your `.env` file:

```env
# OAuth Client Credentials (required)
ACCOUNTING_BRIDGE_CLIENT_ID=your-client-id
ACCOUNTING_BRIDGE_CLIENT_SECRET=your-client-secret

# OAuth Server (required)
ACCOUNTING_BRIDGE_BASE_URL=https://oauth-server.example.com

# OAuth Authorization Parameters
ACCOUNTING_BRIDGE_OAUTH_PROMPT=consent        # Options: none, consent, login

# Callback URL (optional - auto-generated if not set)
ACCOUNTING_BRIDGE_REDIRECT_URL=https://yourapp.com/accounting/callback

# Success Redirect (where to go after successful auth)
ACCOUNTING_BRIDGE_SUCCESS_REDIRECT=/dashboard

# Route Configuration
ACCOUNTING_BRIDGE_ROUTE_PREFIX=accounting     # Route prefix for auth endpoints
ACCOUNTING_BRIDGE_PATH_REDIRECT=login         # Path for redirect endpoint
ACCOUNTING_BRIDGE_PATH_CALLBACK=callback      # Path for callback endpoint
ACCOUNTING_BRIDGE_MIDDLEWARE=web              # Comma-separated middleware list

# JWT Public Key Path (for token verification)
ACCOUNTING_BRIDGE_KEY_PATH=/path/to/oauth-public.key
```

### 3. Configuration Options

The `config/esanj/auth_bridge.php` file provides:

| Option | Description |
|--------|-------------|
| `client_id` | OAuth 2.0 Client ID from authorization server |
| `client_secret` | OAuth 2.0 Client Secret |
| `base_url` | Base URL of OAuth authorization server |
| `redirect_url` | Callback URL (auto-generated from APP_URL if not set) |
| `auth2_prompt` | OAuth prompt parameter: `none`, `consent`, or `login` |
| `success_redirect` | Where to redirect after successful authentication |
| `routes.prefix` | Route prefix for package endpoints |
| `routes.middleware` | Middleware applied to package routes |
| `route_path.redirect` | Path for authorization redirect endpoint |
| `route_path.callback` | Path for OAuth callback endpoint |
| `public_key_path` | Path to OAuth server's public key for JWT verification |

## Routes

The package registers these routes (customizable via config):

| Method | Path | Name | Description |
|--------|------|------|-------------|
| GET | `/{prefix}/{redirect}` | `auth-bridge.redirect` | Initiates OAuth flow |
| GET | `/{prefix}/{callback}` | `auth-bridge.callback` | Handles OAuth callback |

**Default URLs:**
- Redirect: `https://yourapp.com/accounting/login`
- Callback: `https://yourapp.com/accounting/callback`

## Usage

### Basic Authentication Flow

#### 1. Redirect User to OAuth Server

In your login view or controller:

```php
// Simple redirect
return redirect()->route('auth-bridge.redirect');

// With custom success redirect
return redirect()->route('auth-bridge.redirect', [
    'success_redirect' => '/admin/dashboard'
]);

// With custom callback URL
return redirect()->route('auth-bridge.redirect', [
    'callback_url' => 'https://yourapp.com/custom-callback'
]);
```

#### 2. Listen to TokenReceived Event (IMPORTANT!)

This is the **recommended approach** for handling authentication. Create a listener for the `TokenReceived` event:

```bash
php artisan make:listener HandleTokenReceived
```

**app/Listeners/HandleTokenReceived.php:**

```php
<?php

namespace App\Listeners;

use Esanj\AuthBridge\Events\TokenReceived;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class HandleTokenReceived
{
    public function handle(TokenReceived $event): void
    {
        $tokenData = $event->tokenData;

        // Option 1: Extract user info from JWT
        $jwt = app(\Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface::class)
            ->extractJwt($tokenData->accessToken);

        // Find or create user in your local database
        $user = User::firstOrCreate(
            ['oauth_id' => $jwt->sub],
            [
                'email' => $jwt->email,
                'name' => $jwt->name,
                // ... other fields
            ]
        );

        // Log the user in
        Auth::login($user);

        // Store token for API calls (optional)
        $user->update([
            'access_token' => $tokenData->accessToken,
            'refresh_token' => $tokenData->refreshToken,
            'token_expires_at' => $tokenData->expiresAt,
        ]);
    }
}
```

**app/Providers/EventServiceProvider.php:**

```php
use Esanj\AuthBridge\Events\TokenReceived;
use App\Listeners\HandleTokenReceived;

protected $listen = [
    TokenReceived::class => [
        HandleTokenReceived::class,
    ],
];
```

#### 3. Access Token Data (Alternative Approach)

If you prefer not to use events, tokens are stored in the session:

```php
// Get token data from session
$accessToken = session('auth_bridge.access_token');
$refreshToken = session('auth_bridge.refresh_token');
$expiresAt = session('auth_bridge.expires_at');

// Build authorization header
$tokenType = session('auth_bridge.token_type', 'Bearer');
$authHeader = "{$tokenType} {$accessToken}";
```

### Client Credentials Flow

For server-to-server authentication without user interaction:

```php
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;

// Inject the service
public function __construct(
    private ClientCredentialsServiceInterface $clientCredentials
) {}

// Get access token (automatically cached)
$tokenData = $this->clientCredentials->getAccessToken(
    clientId: config('esanj.auth_bridge.client_id'),
    clientSecret: config('esanj.auth_bridge.client_secret'),
    scope: '*'  // optional
);

// Use the token
$response = Http::withHeaders([
    'Authorization' => $tokenData->getAuthorizationHeader(),
])->get('https://api.example.com/data');

// Invalidate cached token (if needed)
$this->clientCredentials->invalidateToken(
    clientId: config('esanj.auth_bridge.client_id'),
    scope: '*'
);
```

**Key Features:**
- Tokens are automatically cached until expiration
- 60-second buffer before expiration triggers refresh
- Failed requests dispatch `TokenExchangeFailed` event

### JWT Token Extraction

Extract and verify JWT access tokens:

```php
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Esanj\AuthBridge\Exceptions\ExtractJWTException;

try {
    $jwt = app(ClientCredentialsServiceInterface::class)
        ->extractJwt($accessToken);

    // Access JWT claims
    $userId = $jwt->sub;
    $email = $jwt->email;
    $scopes = $jwt->scopes;

} catch (ExtractJWTException $e) {
    // Handle invalid token or missing public key
    report($e);
}
```

**Requirements:**
- Public key file must exist at the configured path
- Public key must match the OAuth server's private key

## Events

The package dispatches these events for advanced integration:

### TokenReceived

Dispatched when access token is successfully obtained.

```php
namespace Esanj\AuthBridge\Events;

class TokenReceived
{
    public readonly TokenData $tokenData;
    public readonly string $grantType; // 'authorization_code' or 'client_credentials'
}
```

**Use Cases:**
- Create/update local user records
- Log authentication events
- Trigger post-authentication workflows

### TokenExchangeFailed

Dispatched when token exchange fails.

```php
namespace Esanj\AuthBridge\Events;

class TokenExchangeFailed
{
    public readonly AuthBridgeException $exception;
    public readonly string $grantType;
}
```

**Use Cases:**
- Log authentication failures
- Alert monitoring systems
- Display user-friendly error messages

### AuthorizationRedirecting

Dispatched before redirecting to OAuth server.

```php
namespace Esanj\AuthBridge\Events;

class AuthorizationRedirecting
{
    public readonly AuthorizationRequest $request;
    public readonly string $authorizationUrl;
}
```

**Use Cases:**
- Log authorization attempts
- Modify authorization parameters
- Audit OAuth flows


### State Validation

The package automatically:
- Generates random state tokens (40 characters)
- Validates state on callback (production only)
- Throws `InvalidStateException` on mismatch

### Best Practices

1. **Never commit secrets** to version control
2. **Use HTTPS** in production for all URLs
3. **Configure allowed callbacks** before deploying
4. **Store tokens securely** (encrypted database columns)
5. **Implement token refresh** for long-lived sessions
6. **Validate JWT signatures** using the public key
7. **Monitor failed attempts** via `TokenExchangeFailed` event

## Advanced Usage

### Custom Callback Handling

Override the callback URL at runtime:

```php
return redirect()->route('auth-bridge.redirect', [
    'callback_url' => 'https://custom-domain.com/oauth/callback'
]);
```

### Dynamic Success Redirects

Specify where to redirect after authentication:

```php
return redirect()->route('auth-bridge.redirect', [
    'success_redirect' => '/admin/dashboard'
]);
```

### Facade Usage

Use the `AuthBridge` facade for direct service access:

```php
use Esanj\AuthBridge\Facades\AuthBridge;

// Build authorization URL
$url = AuthBridge::buildAuthorizationUrl();

// Exchange code for token
$tokenData = AuthBridge::exchangeAuthorizationCodeForAccessToken($code);

// Get configuration
$clientId = AuthBridge::getClientId();
$baseUrl = AuthBridge::getBaseUrl();
```

## Error Handling

The package throws these exceptions:

| Exception | When | How to Handle |
|-----------|------|---------------|
| `InvalidStateException` | State validation fails | Log attempt, show error page |
| `TokenExchangeException` | Token exchange fails | Check credentials, log error |
| `TokenRequestException` | Client credentials fail | Verify client_id/secret |
| `ExtractJWTException` | JWT verification fails | Check public key, token validity |
| `AuthBridgeException` | Base exception class | Catch-all handler |

**Example:**

```php
use Esanj\AuthBridge\Exceptions\TokenExchangeException;

try {
    $tokenData = $authBridge->exchangeAuthorizationCodeForAccessToken($code);
} catch (TokenExchangeException $e) {
    logger()->error('OAuth token exchange failed', [
        'error' => $e->getMessage(),
        'context' => $e->getContext(),
    ]);

    return redirect('/login')->with('error', 'Authentication failed');
}
```

## Credits

Developed and maintained by **Esanj Tech Team**.
