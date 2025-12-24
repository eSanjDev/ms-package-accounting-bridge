<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Exceptions;

class InvalidStateException extends AuthBridgeException
{
    public static function mismatch(): self
    {
        return new self(
            message: 'The OAuth state parameter is invalid or has expired.',
            code: 400
        );
    }

    public static function missing(): self
    {
        return new self(
            message: 'The OAuth state parameter is missing from the session.',
            code: 400
        );
    }
}