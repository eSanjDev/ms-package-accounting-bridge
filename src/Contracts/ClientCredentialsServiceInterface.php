<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Contracts;

use Esanj\AuthBridge\DTOs\TokenData;
use Esanj\AuthBridge\Exceptions\TokenRequestException;

interface ClientCredentialsServiceInterface
{
    /**
     * Get access token using client credentials grant.
     *
     * @throws TokenRequestException
     */
    public function getAccessToken(string $clientId, string $clientSecret, ?string $scope = null): TokenData;

    /**
     * Invalidate cached token for specific client.
     */
    public function invalidateToken(string $clientId, ?string $scope = null): void;
}