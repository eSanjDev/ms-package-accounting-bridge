<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Facades;

use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\AuthBridge\DTOs\TokenData;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Session;

/**
 * @method static TokenData exchangeAuthorizationCodeForAccessToken(string $code, ?string $redirectUri = null)
 * @method static string buildAuthorizationUrl(string $state, ?string $callbackUrl = null)
 * @method static string getRedirectUrl(?string $customUrl = null)
 * @method static string getClientId()
 * @method static string getBaseUrl()
 *
 * @see \Esanj\AuthBridge\Services\AuthBridgeService
 */
class AuthBridge extends Facade
{
    private const SESSION_TOKEN_KEY = 'auth_bridge';

    protected static function getFacadeAccessor(): string
    {
        return AuthBridgeServiceInterface::class;
    }

    /**
     * Get the stored token data from session.
     */
    public static function getToken(): ?array
    {
        return Session::get(self::SESSION_TOKEN_KEY);
    }

    /**
     * Get the access token string from session.
     */
    public static function getAccessToken(): ?string
    {
        return static::getToken()['access_token'] ?? null;
    }

    /**
     * Check if user has a valid token in session.
     */
    public static function hasToken(): bool
    {
        return static::getAccessToken() !== null;
    }

    /**
     * Clear the stored token from session.
     */
    public static function clearToken(): void
    {
        Session::forget(self::SESSION_TOKEN_KEY);
    }

    /**
     * Get Authorization header value.
     */
    public static function getAuthorizationHeader(): ?string
    {
        $token = static::getToken();
        if ($token === null) {
            return null;
        }

        $tokenType = $token['token_type'] ?? 'Bearer';

        return "{$tokenType} {$token['access_token']}";
    }
}