# 📚 AuthBridge — Complete Beginner's Guide

This guide assumes **you have never used this package before**. It explains everything in plain language, step by
step, with copy‑paste code. If you can edit a `.env` file and create one event listener, you can finish a working
OAuth login.

> 💡 **What is this package?** It connects your Laravel app to an **OAuth 2.0 login server** (the place that
> actually checks usernames/passwords). Your app sends the user there to log in; the server sends them back with a
> token; this package catches that token and hands it to you. You decide what to do with it (usually: find/create a
> local user and log them in).

---

## Table of Contents

1. [The big picture](#1-the-big-picture)
2. [Two flows: which one do you need?](#2-two-flows-which-one-do-you-need)
3. [Requirements](#3-requirements)
4. [Installation](#4-installation)
5. [Configuration & `.env`](#5-configuration--env)
6. [The two routes you get](#6-the-two-routes-you-get)
7. [Recipe: add a "Login" button](#7-recipe-add-a-login-button)
8. [Recipe: handle the token (log the user in)](#8-recipe-handle-the-token-log-the-user-in)
9. [Recipe: read the token later](#9-recipe-read-the-token-later)
10. [Recipe: server‑to‑server tokens (Client Credentials)](#10-recipe-server-to-server-tokens-client-credentials)
11. [Recipe: verify a JWT](#11-recipe-verify-a-jwt)
12. [Recipe: react to failures & other events](#12-recipe-react-to-failures--other-events)
13. [Recipe: change where users land after login](#13-recipe-change-where-users-land-after-login)
14. [Recipe: change the route prefix/paths](#14-recipe-change-the-route-prefixpaths)
15. [How security (state/CSRF) works](#15-how-security-statecsrf-works)
16. [Configuration reference](#16-configuration-reference)
17. [Events, exceptions & facade reference](#17-events-exceptions--facade-reference)
18. [Troubleshooting](#18-troubleshooting)
19. [Cheat sheet](#19-cheat-sheet)

---

## 1. The big picture

The **Authorization Code** login looks like this:

```
Your app                         AuthBridge (this package)        OAuth server
   |   user clicks "Login"             |                               |
   |---------------------------------->|  build URL + save state       |
   |                                   |------ redirect user --------->|  user logs in
   |                                   |                               |
   |                                   |<----- redirect back (?code) --|
   |                                   |  validate state               |
   |                                   |  exchange code -> token       |
   |                                   |  save token in session        |
   |                                   |  fire TokenReceived event     |
   |<-- your listener logs user in ----|                               |
   |   redirect to success_redirect    |                               |
```

**Your job is small:** (1) send users to the redirect route, and (2) write **one listener** that turns the token
into a logged‑in user. The package does everything in between.

---

## 2. Two flows: which one do you need?

| Flow | Use it when… | What you call |
|------|--------------|---------------|
| **Authorization Code** | A **human** logs into your app. | `redirect()->route('auth-bridge.redirect')` |
| **Client Credentials** | Your app calls **another service** with no human involved. | `ClientCredentialsServiceInterface::getAccessToken()` |

Most apps use the first for login. Use the second when your backend needs its own token to call an API.

---

## 3. Requirements

- PHP 8.1–8.4, Laravel 10–13.
- An **OAuth 2.0 server** you can reach, plus a **client id/secret** issued by it.
- For JWT verification: the server's **RS256 public key** as a file on your server.
- `firebase/php-jwt` is pulled in automatically.

---

## 4. Installation

```bash
composer require esanj/auth-bridge
php artisan vendor:publish --tag="esanj-auth-bridge-config"
```

The provider and the `AuthBridge` facade are auto‑discovered — nothing else to register. The publish step creates
`config/esanj/auth_bridge.php`.

---

## 5. Configuration & `.env`

Set at least these three (ask whoever runs your OAuth server for the values):

```env
ACCOUNTING_BRIDGE_CLIENT_ID=your-client-id
ACCOUNTING_BRIDGE_CLIENT_SECRET=your-client-secret
ACCOUNTING_BRIDGE_BASE_URL=https://oauth-server.example.com
```

Then choose where users land after login, and (for JWT) the public key path:

```env
ACCOUNTING_BRIDGE_SUCCESS_REDIRECT=/dashboard
ACCOUNTING_BRIDGE_KEY_PATH=/var/www/storage/oauth-public.key
```

> ⚠️ After editing `.env` or config, run `php artisan config:clear`.

**Important — the callback URL must match.** By default your callback is
`APP_URL` + `/accounting/callback`. Register **exactly that URL** as the allowed redirect URI in your OAuth
server, or override it with `ACCOUNTING_BRIDGE_REDIRECT_URL` to match what's registered.

---

## 6. The two routes you get

| You send users to… | URL (default) | What it does |
|--------------------|---------------|--------------|
| `route('auth-bridge.redirect')` | `/accounting/login` | Starts login (redirects to the OAuth server). |
| (automatic) | `/accounting/callback` | The OAuth server returns here; the package handles it. |

You normally only ever reference the **redirect** route. The callback runs itself.

---

## 7. Recipe: add a "Login" button

Point any link or button at the redirect route:

```blade
<a href="{{ route('auth-bridge.redirect') }}" class="btn btn-primary">Login</a>
```

Or from a controller:

```php
return redirect()->route('auth-bridge.redirect');
```

Clicking it sends the user to the OAuth server to sign in. That's the whole "start login" step.

---

## 8. Recipe: handle the token (log the user in)

When the user comes back, the package fires a **`TokenReceived`** event. Write a listener to log them in.

**Step 1 — create the listener** `app/Listeners/HandleTokenReceived.php`:

```php
<?php

namespace App\Listeners;

use App\Models\User;
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Esanj\AuthBridge\Events\TokenReceived;
use Illuminate\Support\Facades\Auth;

class HandleTokenReceived
{
    public function handle(TokenReceived $event): void
    {
        $token = $event->tokenData;   // contains accessToken, refreshToken, expiresAt, ...

        // Read the user info out of the JWT access token:
        $jwt = app(ClientCredentialsServiceInterface::class)->extractJwt($token->accessToken);

        // Find or create a local user, then log them in:
        $user = User::firstOrCreate(
            ['oauth_id' => $jwt->sub],
            ['email' => $jwt->email ?? null, 'name' => $jwt->name ?? null],
        );

        Auth::login($user);
    }
}
```

**Step 2 — register it.**
- **Laravel 11+** auto‑discovers listeners in `app/Listeners` — nothing to do.
- **Laravel 10** — add it to `app/Providers/EventServiceProvider.php`:

  ```php
  protected $listen = [
      \Esanj\AuthBridge\Events\TokenReceived::class => [
          \App\Listeners\HandleTokenReceived::class,
      ],
  ];
  ```

That's it — users can now log in. After the listener runs, the package redirects them to your
`success_redirect`.

> 💡 `TokenReceived` also fires for the client‑credentials flow. If you only want the login flow, check
> `$event->grantType === 'authorization_code'`.

---

## 9. Recipe: read the token later

The token is also saved in the session (key `auth_bridge`). Read it anywhere:

```php
$accessToken = session('auth_bridge.access_token');
$refreshToken = session('auth_bridge.refresh_token');
$expiresAt    = session('auth_bridge.expires_at');
```

Or use the facade helpers:

```php
use Esanj\AuthBridge\Facades\AuthBridge;

AuthBridge::hasToken();                // is there a token in the session?
AuthBridge::getAccessToken();          // the raw access token string
AuthBridge::getAuthorizationHeader();  // "Bearer xxxx" — ready for an HTTP header
AuthBridge::clearToken();              // remove it (e.g. on logout)
```

Calling another API with it:

```php
Http::withHeaders(['Authorization' => AuthBridge::getAuthorizationHeader()])
    ->get('https://api.example.com/me');
```

---

## 10. Recipe: server‑to‑server tokens (Client Credentials)

When your backend needs its own token (no user), inject the client‑credentials service:

```php
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;

class ReportSync
{
    public function __construct(private ClientCredentialsServiceInterface $cc) {}

    public function run(): void
    {
        $token = $this->cc->getAccessToken(
            clientId: config('esanj.auth_bridge.client_id'),
            clientSecret: config('esanj.auth_bridge.client_secret'),
            scope: '*', // optional
        );

        Http::withHeaders(['Authorization' => $token->getAuthorizationHeader()])
            ->get('https://api.example.com/reports');
    }
}
```

- The token is **cached automatically** and reused until ~60 seconds before it expires.
- Need a fresh one immediately? `$this->cc->invalidateToken(config('esanj.auth_bridge.client_id'), '*');`

---

## 11. Recipe: verify a JWT

If you receive a JWT (e.g. from another service) and want to trust its contents, verify it with the OAuth server's
**public key**:

```php
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Esanj\AuthBridge\Exceptions\ExtractJWTException;

try {
    $claims = app(ClientCredentialsServiceInterface::class)->extractJwt($jwtString);
    $userId = $claims->sub;
    $audience = $claims->aud ?? null;
} catch (ExtractJWTException $e) {
    // token is invalid/expired, or the public key file is missing
    abort(401, 'Invalid token');
}
```

The public key must exist at `config('esanj.auth_bridge.public_key_path')` (default
`storage/oauth-public.key`) and be the RS256 public key matching the server's private key.

---

## 12. Recipe: react to failures & other events

Listen to these just like `TokenReceived`:

| Event | Fires when | Use it to… |
|-------|------------|------------|
| `TokenExchangeFailed` | A token request/exchange fails. | Log it, alert monitoring, show a friendly error. |
| `AuthorizationRedirecting` | Right before sending the user to the OAuth server. | Audit/log login attempts. |

```php
use Esanj\AuthBridge\Events\TokenExchangeFailed;

class LogAuthFailure
{
    public function handle(TokenExchangeFailed $event): void
    {
        logger()->error('OAuth failed', [
            'grant' => $event->grantType,
            'message' => $event->exception->getMessage(),
            'context' => $event->exception->getContext(),
        ]);
    }
}
```

---

## 13. Recipe: change where users land after login

Set it in `.env`:

```env
ACCOUNTING_BRIDGE_SUCCESS_REDIRECT=/admin/dashboard
```

(Then `php artisan config:clear`.)

> ⚠️ This is a **config‑level** setting. The current version does **not** read a `?success_redirect=` query
> parameter at runtime — set it here, or branch inside your `TokenReceived` listener (e.g. redirect based on the
> user's role) instead of relying on `success_redirect`.

---

## 14. Recipe: change the route prefix/paths

By default the routes live under `/accounting`. Change them in `.env`:

```env
ACCOUNTING_BRIDGE_ROUTE_PREFIX=auth        # → /auth/login and /auth/callback
ACCOUNTING_BRIDGE_PATH_REDIRECT=login
ACCOUNTING_BRIDGE_PATH_CALLBACK=callback
ACCOUNTING_BRIDGE_MIDDLEWARE=web           # comma-separated, e.g. web,throttle:10,1
```

> If you change the prefix or callback path, update the **registered redirect URI** on your OAuth server (and
> `ACCOUNTING_BRIDGE_REDIRECT_URL` if you set it explicitly) so they still match.

---

## 15. How security (state/CSRF) works

- When login starts, the package generates a random 40‑character **state** and stores it in the session.
- On callback, it checks the returned `state` against the stored one and throws `InvalidStateException` if they
  don't match — this blocks CSRF/replay attacks.
- **This check runs only in production** (`app()->isProduction()`). In local/testing it's skipped so you can test
  without a perfectly matching session. Don't rely on the check being active in `local`/`testing`.

---

## 16. Configuration reference

File: `config/esanj/auth_bridge.php` (key `esanj.auth_bridge`).

| Key | Env | Default | Meaning |
|-----|-----|---------|---------|
| `client_id` | `ACCOUNTING_BRIDGE_CLIENT_ID` | — | OAuth client id. |
| `client_secret` | `ACCOUNTING_BRIDGE_CLIENT_SECRET` | — | OAuth client secret. |
| `base_url` | `ACCOUNTING_BRIDGE_BASE_URL` | — | OAuth server base URL. |
| `redirect_url` | `ACCOUNTING_BRIDGE_REDIRECT_URL` | `APP_URL/{prefix}/{callback}` | Callback URL sent to the server. |
| `auth2_prompt` | `ACCOUNTING_BRIDGE_OAUTH_PROMPT` | `consent` | `none` / `consent` / `login`. |
| `success_redirect` | `ACCOUNTING_BRIDGE_SUCCESS_REDIRECT` | `/` | Where to go after login. |
| `routes.prefix` | `ACCOUNTING_BRIDGE_ROUTE_PREFIX` | `accounting` | URL prefix for both routes. |
| `routes.middleware` | `ACCOUNTING_BRIDGE_MIDDLEWARE` | `web` | Middleware (comma‑separated). |
| `route_path.redirect` | `ACCOUNTING_BRIDGE_PATH_REDIRECT` | `login` | "Start login" path. |
| `route_path.callback` | `ACCOUNTING_BRIDGE_PATH_CALLBACK` | `callback` | Callback path. |
| `public_key_path` | `ACCOUNTING_BRIDGE_KEY_PATH` | `storage/oauth-public.key` | RS256 public key file. |
| `session_state_key` | — | `auth_bridge_state` | Session key for the state token. |
| `session_token_key` | — | `auth_bridge` | Session key for the stored token. |

---

## 17. Events, exceptions & facade reference

**Events:** `TokenReceived` (`tokenData`, `grantType`), `TokenExchangeFailed` (`exception`, `grantType`),
`AuthorizationRedirecting` (`request`, `authorizationUrl`).

**Exceptions** (all extend `AuthBridgeException`, which has `getContext()`):
`InvalidStateException`, `TokenExchangeException`, `TokenRequestException`, `ExtractJWTException`.

**`AuthBridge` facade:**
`buildAuthorizationUrl()`, `exchangeAuthorizationCodeForAccessToken($code)`, `getClientId()`, `getBaseUrl()`,
plus session helpers `getToken()`, `getAccessToken()`, `hasToken()`, `getAuthorizationHeader()`, `clearToken()`.

**`TokenData` DTO:** `accessToken`, `tokenType`, `expiresIn`, `refreshToken`, `scope`, `expiresAt`,
`isExpired()`, `getAuthorizationHeader()`.

---

## 18. Troubleshooting

**After login I get an `InvalidStateException` (in production).**
The session didn't survive the round trip. Make sure the redirect route uses the `web` middleware (it does by
default), sessions are configured, and you're not switching domains mid‑flow.

**The OAuth server rejects the request with "redirect_uri mismatch".**
The callback URL your app sends must exactly match what's registered on the server. Check `APP_URL`, the prefix,
and `ACCOUNTING_BRIDGE_REDIRECT_URL`.

**`ExtractJWTException: Public Key not found`.**
The RS256 public key file isn't at `public_key_path`. Put it there (default `storage/oauth-public.key`) or set
`ACCOUNTING_BRIDGE_KEY_PATH`, then `php artisan config:clear`.

**`Class "Firebase\JWT\JWT" not found`.**
Run `composer update` so `firebase/php-jwt` (a dependency of this package) is installed.

**`TokenExchangeException` right after the callback.**
Wrong `client_id`/`client_secret`/`base_url`, or the `redirect_uri` didn't match. Check the exception's
`getContext()` and your OAuth server logs.

**Config changes seem ignored.**
`php artisan config:clear` (and re‑cache with `config:cache` in production).

**My listener never runs.**
On Laravel 10 you must register it in `EventServiceProvider`. On 11+ confirm it's in `app/Listeners` and typed
against `TokenReceived`.

---

## 19. Cheat sheet

```bash
composer require esanj/auth-bridge
php artisan vendor:publish --tag="esanj-auth-bridge-config"
php artisan config:clear     # after any config/.env change
```

```php
// Start login
return redirect()->route('auth-bridge.redirect');

// Handle the token (in a TokenReceived listener)
$jwt = app(ClientCredentialsServiceInterface::class)->extractJwt($event->tokenData->accessToken);
Auth::login(User::firstOrCreate(['oauth_id' => $jwt->sub], [...]));

// Read the token later
session('auth_bridge.access_token');
AuthBridge::getAuthorizationHeader();   // "Bearer ..."

// Server-to-server token
$token = app(ClientCredentialsServiceInterface::class)
    ->getAccessToken(config('esanj.auth_bridge.client_id'), config('esanj.auth_bridge.client_secret'));
```

| I want to...                     | Do this                                                      |
|----------------------------------|--------------------------------------------------------------|
| Add a login button               | link to `route('auth-bridge.redirect')`                      |
| Log the user in after OAuth      | a `TokenReceived` listener → `Auth::login(...)`              |
| Get the token later              | `session('auth_bridge.access_token')` or `AuthBridge::getAccessToken()` |
| Call another API as the server   | `ClientCredentialsServiceInterface::getAccessToken()`        |
| Verify a JWT                     | `extractJwt($jwt)` + an `oauth-public.key` file              |
| Change the landing page          | `ACCOUNTING_BRIDGE_SUCCESS_REDIRECT`                          |
| Change the URL prefix            | `ACCOUNTING_BRIDGE_ROUTE_PREFIX`                             |

---

Need the quick reference instead? See the [README](../README.md).