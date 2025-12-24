<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\DTOs;

use DateTimeImmutable;
use JsonSerializable;

final class TokenData implements JsonSerializable
{
    public readonly DateTimeImmutable $expiresAt;

    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType,
        public readonly int $expiresIn,
        public readonly ?string $refreshToken = null,
        public readonly ?string $scope = null,
    ) {
        $this->expiresAt = (new DateTimeImmutable())->modify("+{$expiresIn} seconds");
    }

    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: $data['access_token'],
            tokenType: $data['token_type'] ?? 'Bearer',
            expiresIn: (int) ($data['expires_in'] ?? 3600),
            refreshToken: $data['refresh_token'] ?? null,
            scope: $data['scope'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'scope' => $this->scope,
            'expires_at' => $this->expiresAt->format('Y-m-d H:i:s'),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }

    public function getAuthorizationHeader(): string
    {
        return "{$this->tokenType} {$this->accessToken}";
    }
}