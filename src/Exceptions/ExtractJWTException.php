<?php

namespace Esanj\AuthBridge\Exceptions;

class ExtractJWTException extends AuthBridgeException
{
    public static function publicKeyNotFound(): static
    {
        return new static(
            message: 'Public Key not found',
            code: 401
        );
    }

    public static function invalidToken(string $message): static
    {
        return new static(
            message: $message,
            code: 401
        );
    }
}
