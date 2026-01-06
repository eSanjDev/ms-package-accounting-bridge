<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Contracts;

use Esanj\AuthBridge\DTOs\TokenData;
use Esanj\AuthBridge\Exceptions\TokenExchangeException;

interface AuthBridgeServiceInterface
{
    /**
     * Build the authorization URL for OAuth redirect.
     */
    public function buildAuthorizationUrl(): string;

    /**
     * Exchange authorization code for access token.
     *
     * @throws TokenExchangeException
     */
    public function exchangeAuthorizationCodeForAccessToken(string $code): TokenData;
}
