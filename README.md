# AuthBridge

**AuthBridge** is a Laravel package that connects your app to an **OAuth 2.0** authorization server. It handles the
**Authorization Code** flow (redirect users to log in, then receive their token) and the **Client Credentials**
flow (server‑to‑server tokens), plus **RS256 JWT verification** — with an event‑driven design so you decide what
happens when a token arrives.

> Originally built for the Esanj Accounting service, but it works with any OAuth 2.0 server. Many Esanj packages
> (e.g. `esanj/managers`) depend on it for login.

## Features

- OAuth 2.0 **Authorization Code** and **Client Credentials** grants.
- CSRF **state** validation on callback (enforced in production).
- **Event‑driven**: `TokenReceived`, `TokenExchangeFailed`, `AuthorizationRedirecting`.
- **JWT** extraction & verification (RS256) against the OAuth server's public key.
- Automatic **token caching** for the Client Credentials flow.
- A facade with handy **session token helpers**.

## Requirements

- **PHP:** 8.1 – 8.4
- **Laravel:** 10.x – 13.x
- **OAuth Server:** any OAuth 2.0 compliant server
- `firebase/php-jwt` (installed automatically) — used for JWT verification.

## Installation

```bash
composer require esanj/auth-bridge
```

The service provider and the `AuthBridge` facade are auto‑discovered.

## Configuration

### 1. Publish the config

```bash
php artisan vendor:publish --tag="esanj-auth-bridge-config"
```

This creates `config/esanj/auth_bridge.php` (merged internally under the key `esanj.auth_bridge`).

### 2. Environment variables

```env
# OAuth client credentials (required)
ACCOUNTING_BRIDGE_CLIENT_ID=your-client-id
ACCOUNTING_BRIDGE_CLIENT_SECRET=your-client-secret

# OAuth server base URL (required)
ACCOUNTING_BRIDGE_BASE_URL=https://oauth-server.example.com

# Authorization prompt: none | consent | login
ACCOUNTING_BRIDGE_OAUTH_PROMPT=consent

# Callback URL (optional — auto-generated from APP_URL + prefix + callback path if unset)
ACCOUNTING_BRIDGE_REDIRECT_URL=https://yourapp.com/accounting/callback

# Where to send the user after a successful login
ACCOUNTING_BRIDGE_SUCCESS_REDIRECT=/dashboard

# Routing
ACCOUNTING_BRIDGE_ROUTE_PREFIX=accounting   # prefix for the two routes
ACCOUNTING_BRIDGE_PATH_REDIRECT=login        # the "start login" path
ACCOUNTING_BRIDGE_PATH_CALLBACK=callback     # the OAuth callback path
ACCOUNTING_BRIDGE_MIDDLEWARE=web             # comma-separated middleware

# JWT public key (RS256) used to verify tokens
ACCOUNTING_BRIDGE_KEY_PATH=/path/to/oauth-public.key
```

### 3. Config options

| Option | Description |
|--------|-------------|
| `client_id` / `client_secret` | OAuth 2.0 credentials. |
| `base_url` | Base URL of the OAuth server. |
| `redirect_url` | Callback URL (auto‑generated from `APP_URL` if not set). |
| `auth2_prompt` | OAuth `prompt`: `none`, `consent`, or `login`. |
| `success_redirect` | Where to redirect after a successful login. |
| `routes.prefix` / `routes.middleware` | Prefix and middleware for the package routes. |
| `route_path.redirect` / `route_path.callback` | Paths for the redirect and callback endpoints. |
| `public_key_path` | Path to the OAuth server's RS256 public key. |
| `session_state_key` / `session_token_key` | Session keys (`auth_bridge_state` / `auth_bridge`). |

## Routes

| Method | Path (default) | Name | Description |
|--------|----------------|------|-------------|
| GET | `/{prefix}/{redirect}` → `/accounting/login` | `auth-bridge.redirect` | Starts the OAuth flow (redirects to the server). |
| GET | `/{prefix}/{callback}` → `/accounting/callback` | `auth-bridge.callback` | Handles the callback and stores the token. |

## Usage

### Authorization Code flow (user login)

**Step 1 — send the user to log in:**

```php
return redirect()->route('auth-bridge.redirect');
```

The package builds the authorization URL, stores a random `state` in the session, fires
`AuthorizationRedirecting`, and redirects to the OAuth server.

**Step 2 — the callback is handled for you.** On return the package validates `state` (in production), exchanges
the `code` for a token, stores the token in the session under `auth_bridge`, fires `TokenReceived`, and redirects
to `config('esanj.auth_bridge.success_redirect')`.

> ℹ️ `success_redirect` and the callback URL are taken from **config/env**. (Passing them as query parameters to
> the route is **not** currently supported — see [Notes](#notes--limitations).)

**Step 3 — react to the token via the `TokenReceived` event (recommended).**

```php
// app/Listeners/HandleTokenReceived.php
use Esanj\AuthBridge\Events\TokenReceived;
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class HandleTokenReceived
{
    public function handle(TokenReceived $event): void
    {
        $token = $event->tokenData;                 // TokenData DTO
        // $event->grantType === 'authorization_code'

        $jwt = app(ClientCredentialsServiceInterface::class)->extractJwt($token->accessToken);

        $user = User::firstOrCreate(
            ['oauth_id' => $jwt->sub],
            ['email' => $jwt->email ?? null, 'name' => $jwt->name ?? null],
        );

        Auth::login($user);
    }
}
```

Register it (Laravel 11+ auto‑discovers listeners; otherwise add it to your `EventServiceProvider`).

**Alternative — read the token from the session:**

```php
$accessToken = session('auth_bridge.access_token');
$refreshToken = session('auth_bridge.refresh_token');
$expiresAt   = session('auth_bridge.expires_at');

// Or via the facade:
use Esanj\AuthBridge\Facades\AuthBridge;
$header = AuthBridge::getAuthorizationHeader(); // "Bearer xxx" or null
```

### Client Credentials flow (server‑to‑server)

```php
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;

public function __construct(private ClientCredentialsServiceInterface $cc) {}

$token = $this->cc->getAccessToken(
    clientId: config('esanj.auth_bridge.client_id'),
    clientSecret: config('esanj.auth_bridge.client_secret'),
    scope: '*' // optional
);

$response = Http::withHeaders([
    'Authorization' => $token->getAuthorizationHeader(),
])->get('https://api.example.com/data');

// Force a refresh on the next call:
$this->cc->invalidateToken(config('esanj.auth_bridge.client_id'), '*');
```

Tokens are cached until ~60 seconds before expiry; failures fire `TokenExchangeFailed`.

### JWT extraction

```php
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Esanj\AuthBridge\Exceptions\ExtractJWTException;

try {
    $jwt = app(ClientCredentialsServiceInterface::class)->extractJwt($accessToken);
    $userId = $jwt->sub;
} catch (ExtractJWTException $e) {
    report($e); // invalid token, or public key missing
}
```

Requires the RS256 public key at `config('esanj.auth_bridge.public_key_path')`.

## Events

| Event | Fired when | Payload |
|-------|------------|---------|
| `TokenReceived` | A token is obtained (either flow). | `TokenData $tokenData`, `string $grantType` |
| `TokenExchangeFailed` | A token request/exchange fails. | `AuthBridgeException $exception`, `string $grantType` |
| `AuthorizationRedirecting` | Just before redirecting to the OAuth server. | `AuthorizationRequest $request`, `string $authorizationUrl` |

## Facade

```php
use Esanj\AuthBridge\Facades\AuthBridge;

AuthBridge::buildAuthorizationUrl();                         // build the authorize URL
AuthBridge::exchangeAuthorizationCodeForAccessToken($code);  // exchange a code → TokenData
AuthBridge::getClientId();
AuthBridge::getBaseUrl();

// Session token helpers:
AuthBridge::getToken();                 // array|null
AuthBridge::getAccessToken();           // string|null
AuthBridge::hasToken();                 // bool
AuthBridge::getAuthorizationHeader();   // "Bearer xxx"|null
AuthBridge::clearToken();               // forget the session token
```

## Error handling

| Exception | When |
|-----------|------|
| `InvalidStateException` | The OAuth `state` is missing or doesn't match (production). |
| `TokenExchangeException` | The authorization‑code exchange fails. |
| `TokenRequestException` | The client‑credentials token request fails. |
| `ExtractJWTException` | JWT is invalid/expired, or the public key is missing. |
| `AuthBridgeException` | Base class for all of the above (carries `getContext()`). |

```php
use Esanj\AuthBridge\Exceptions\TokenExchangeException;

try {
    $token = AuthBridge::exchangeAuthorizationCodeForAccessToken($code);
} catch (TokenExchangeException $e) {
    logger()->error('OAuth exchange failed', ['error' => $e->getMessage(), 'context' => $e->getContext()]);
    return redirect('/login')->with('error', 'Authentication failed');
}
```

## Notes & limitations

- **State (CSRF) validation runs only in production** (`app()->isProduction()`). In local/testing environments the
  callback skips the state check for convenience.
- **No runtime query‑parameter overrides.** `success_redirect` and the callback URL come from config/env only;
  passing `?success_redirect=` or `?callback_url=` to the redirect route has no effect in the current version.

## Documentation

For a complete, beginner‑friendly, step‑by‑step walkthrough — wiring up login, handling the token, the
client‑credentials flow, JWT verification, and troubleshooting — see **[docs/GUIDE.md](docs/GUIDE.md)**.

## Credits

Developed and maintained by the **Esanj Tech Team**.