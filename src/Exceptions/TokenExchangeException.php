<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Exceptions;

use Esanj\AuthBridge\Exceptions\Concerns\RedirectsToLogin;

class TokenExchangeException extends AuthBridgeException
{
    use RedirectsToLogin;

    public static function failed(string $error, int $statusCode = 400, array $context = []): self
    {
        return new self(
            message: "Token exchange failed: {$error}",
            code: $statusCode,
            context: $context
        );
    }

    public static function authorizationFailed(?string $errorCode = null, array $context = []): self
    {
        return new self(
            message: $errorCode === null
                ? 'Authorization code is missing'
                : "The authorization server returned an error ({$errorCode})",
            code: 400,
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

    public static function malformedResponse(array $context = []): self
    {
        return new self(
            message: 'OAuth server returned a malformed token response (no access_token)',
            code: 502,
            context: $context
        );
    }
}
