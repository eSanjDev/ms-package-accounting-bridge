<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Facades;

use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\AuthBridge\DTOs\TokenData;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string buildAuthorizationUrl()
 * @method static TokenData exchangeAuthorizationCodeForAccessToken(string $code)
 * @method static TokenData refreshAccessToken(string $refreshToken, ?string $scope = null)
 * @method static TokenData|null getValidToken()
 * @method static string|null getValidAccessToken()
 * @method static string|null getValidAuthorizationHeader()
 * @method static void storeToken(TokenData $tokenData)
 * @method static string getClientId()
 * @method static string getClientSecret()
 * @method static string getBaseUrl()
 * @method static string getRedirectUrl()
 * @method static string getPrompt()
 *
 * @see \Esanj\AuthBridge\Services\AuthBridgeService
 */
class AuthBridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuthBridgeServiceInterface::class;
    }

    /**
     * Get the stored token data as an array (auto-refreshed).
     */
    public static function getToken(): ?array
    {
        return static::getFacadeRoot()->getValidToken()?->toArray();
    }

    /**
     * Get the access token string (auto-refreshed).
     */
    public static function getAccessToken(): ?string
    {
        return static::getFacadeRoot()->getValidAccessToken();
    }

    /**
     * Check whether there is a usable token (auto-refreshed).
     */
    public static function hasToken(): bool
    {
        return static::getFacadeRoot()->getValidToken() !== null;
    }

    /**
     * Clear the stored token from session.
     */
    public static function clearToken(): void
    {
        static::getFacadeRoot()->clearToken();
    }

    /**
     * Revoke the token on the OAuth server, then clear it from session.
     */
    public static function revokeToken(): void
    {
        static::getFacadeRoot()->revokeToken();
    }

    /**
     * Get the "Bearer xxx" authorization header (auto-refreshed).
     */
    public static function getAuthorizationHeader(): ?string
    {
        return static::getFacadeRoot()->getValidAuthorizationHeader();
    }
}