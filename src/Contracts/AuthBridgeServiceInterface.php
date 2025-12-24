<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Contracts;

use Esanj\AuthBridge\DTOs\TokenData;
use Esanj\AuthBridge\Exceptions\TokenExchangeException;

interface AuthBridgeServiceInterface
{
    /**
     * Exchange authorization code for access token.
     *
     * @throws TokenExchangeException
     */
    public function exchangeAuthorizationCodeForAccessToken(string $code, ?string $redirectUri = null): TokenData;

    /**
     * Build the authorization URL for OAuth redirect.
     */
    public function buildAuthorizationUrl(string $state, ?string $callbackUrl = null): string;

    /**
     * Get the configured redirect URL.
     */
    public function getRedirectUrl(?string $customUrl = null): string;
}