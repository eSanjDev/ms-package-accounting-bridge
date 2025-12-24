<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Exceptions;

class TokenRequestException extends AuthBridgeException
{
    public static function failed(string $clientId, string $error, int $statusCode = 400): self
    {
        return new self(
            message: "Client credentials token request failed: {$error}",
            code: $statusCode,
            context: ['client_id' => $clientId]
        );
    }

    public static function connectionFailed(string $clientId, string $error): self
    {
        return new self(
            message: "Failed to connect to OAuth server: {$error}",
            code: 503,
            context: ['client_id' => $clientId]
        );
    }
}