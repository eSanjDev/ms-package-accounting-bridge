<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Contracts;

use Esanj\AuthBridge\DTOs\TokenData;
use Esanj\AuthBridge\Exceptions\TokenExchangeException;

interface AuthBridgeServiceInterface
{
    public function buildAuthorizationUrl(): string;
    
    public function exchangeAuthorizationCodeForAccessToken(string $code): TokenData;
    
    public function refreshAccessToken(string $refreshToken, ?string $scope = null): TokenData;

    public function getValidToken(): ?TokenData;

    public function getValidAccessToken(): ?string;

    public function getValidAuthorizationHeader(): ?string;
    
    public function storeToken(TokenData $tokenData): void;
    
    public function clearToken(): void;
}
