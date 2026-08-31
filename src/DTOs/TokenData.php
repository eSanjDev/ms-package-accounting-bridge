<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\DTOs;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use JsonSerializable;

final readonly class TokenData implements JsonSerializable
{
    public DateTimeImmutable $expiresAt;

    public function __construct(
        public string  $accessToken,
        public string  $tokenType,
        public int     $expiresIn,
        public ?string $refreshToken = null,
        public ?string $scope = null,
        ?DateTimeImmutable $expiresAt = null,
    ) {
        $this->expiresAt = $expiresAt ?? (new DateTimeImmutable())->modify("+{$expiresIn} seconds");
    }

    public static function fromArray(?array $data): self
    {
        if (!is_array($data) || !isset($data['access_token']) || !is_string($data['access_token'])) {
            throw new InvalidArgumentException('Token response is missing a usable access_token.');
        }

        return new self(
            accessToken: $data['access_token'],
            tokenType: is_string($data['token_type'] ?? null) ? $data['token_type'] : 'Bearer',
            expiresIn: (int) ($data['expires_in'] ?? 3600),
            refreshToken: is_scalar($data['refresh_token'] ?? null) ? (string) $data['refresh_token'] : null,
            scope: is_scalar($data['scope'] ?? null) ? (string) $data['scope'] : null,
        );
    }

    public static function fromStorage(array $data): self
    {
        $expiresAt = self::parseStoredExpiry($data['expires_at'] ?? null);

        return new self(
            accessToken: $data['access_token'],
            tokenType: $data['token_type'] ?? 'Bearer',
            expiresIn: (int) ($data['expires_in'] ?? 3600),
            refreshToken: $data['refresh_token'] ?? null,
            scope: $data['scope'] ?? null,
            expiresAt: $expiresAt,
        );
    }

    private static function parseStoredExpiry(mixed $value): DateTimeImmutable
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                // fall through
            }
        }

        return (new DateTimeImmutable())->modify('-1 second');
    }

    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'scope' => $this->scope,
            'expires_at' => $this->expiresAt->format(DateTimeInterface::ATOM),
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

    public function isExpiring(int $bufferSeconds = 0): bool
    {
        if ($bufferSeconds <= 0) {
            return $this->isExpired();
        }

        return $this->expiresAt <= (new DateTimeImmutable())->modify("+{$bufferSeconds} seconds");
    }

    public function hasRefreshToken(): bool
    {
        return $this->refreshToken !== null && $this->refreshToken !== '';
    }

    public function getAuthorizationHeader(): string
    {
        return "{$this->tokenType} {$this->accessToken}";
    }
}
