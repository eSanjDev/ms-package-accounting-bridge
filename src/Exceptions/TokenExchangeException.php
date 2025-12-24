<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Exceptions;

class TokenExchangeException extends AuthBridgeException
{
    public static function failed(string $error, int $statusCode = 400, array $context = []): self
    {
        return new self(
            message: "Token exchange failed: {$error}",
            code: $statusCode,
            context: $context
        );
    }

    public static function connectionFailed(string $error): self
    {
        return new self(
            message: "Failed to connect to OAuth server: {$error}",
            code: 503
        );
    }
}