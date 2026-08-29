<?php

namespace Esanj\AuthBridge\Exceptions;

class ExtractJWTException extends AuthBridgeException
{
    public static function publicKeyNotFound(string $path = ''): static
    {
        return new static(
            message: 'Public Key not found or not readable',
            code: 401,
            context: $path === '' ? [] : ['path' => $path]
        );
    }

    public static function publicKeyUnusable(string $reason): static
    {
        return new static(
            message: "Public Key is unusable: {$reason}",
            code: 401
        );
    }

    public static function invalidToken(string $message, array $context = []): static
    {
        return new static(
            message: $message,
            code: 401,
            context: $context
        );
    }
}
