# AuthBridge

**AuthBridge** is a Laravel package that provides seamless integration with the **Accounting** microservice for OAuth2-based authentication flows, including Login and Register. This package acts as a bridge, allowing your Laravel application to authenticate users via the centralized Accounting service.

## Features

- Easy OAuth2 integration with the Accounting microservice
- Secure login and registration redirection
- Handles OAuth2 callback and token exchange
- Stores access and refresh tokens in session
- Fully configurable via environment variables and config file
- Laravel 9+ support

## Installation

```bash
composer require esanj/auth-bridge
```

## Configuration

1. **Publish the configuration file:**

```bash
php artisan vendor:publish --provider="Esanj\\AuthBridge\\AuthBridgeServiceProvider" --tag="config"
```

2. **Set the following environment variables in your `.env` file:**

```env
ACCOUNTING_BRIDGE_CLIENT_ID=your-client-id
ACCOUNTING_BRIDGE_CLIENT_SECRET=your-client-secret
ACCOUNTING_BRIDGE_BASE_URL=https://accounting.example.com
ACCOUNTING_BRIDGE_REDIRECT_ROUTE=auth-bridge.callback
ACCOUNTING_BRIDGE_OAUTH_PROMPT=consent
ACCOUNTING_BRIDGE_SUCCESS_REDIRECT=/
ACCOUNTING_BRIDGE_ROUTE_PREFIX=accounting
ACCOUNTING_BRIDGE_PATH_REDIRECT=login
ACCOUNTING_BRIDGE_PATH_CALLBACK=login
```

3. **Config file options (`config/auth_bridge.php`):**

- `client_id`, `client_secret`, `base_url`: OAuth2 credentials and service URL
- `redirect_route`: Route name for OAuth2 callback
- `auth2_prompt`: OAuth2 prompt type (default: consent)
- `success_redirect`: Where to redirect after successful login
- `routes`: Route group configuration (prefix, middleware)
- `route_path`: Paths for redirect and callback endpoints

## Routes

The package automatically registers the following routes (prefix and paths are configurable):

- `GET /{prefix}/{redirect}` → `AuthBridgeController@redirect`
- `GET /{prefix}/{callback}` → `AuthBridgeController@callback`

## Usage

### Redirect to OAuth2 Login

To initiate the OAuth2 login flow, redirect users to the route:

```php
route('auth-bridge.redirect')
```

This will send the user to the Accounting service's OAuth2 authorization page.

### Handle OAuth2 Callback

The callback route will handle the token exchange and store the tokens in the session. On success, the user is redirected to the configured `success_redirect` path.

### Access Tokens

After successful authentication, tokens are stored in the session under the `auth_bridge` key:

```php
session('auth_bridge.access_token');
session('auth_bridge.refresh_token');
```

## Controller Logic

- **redirect(Request $request):**  
  Generates a random state, builds the OAuth2 authorization URL, and redirects the user.
- **callback(Request $request):**  
  Validates the state, exchanges the code for tokens, stores them in the session, and redirects the user.

## Service Provider

The `AuthBridgeServiceProvider` handles:

- Publishing the config file
- Loading package routes
- Merging default config

## Requirements

- PHP 8.0+
- Laravel 9.0+
- Accounting microservice

## License

MIT
