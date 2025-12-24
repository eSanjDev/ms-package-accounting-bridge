<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Events;

use Esanj\AuthBridge\Exceptions\AuthBridgeException;
use Illuminate\Foundation\Events\Dispatchable;

class TokenExchangeFailed
{
    use Dispatchable;

    public function __construct(
        public readonly AuthBridgeException $exception,
        public readonly string $grantType = 'authorization_code',
    ) {}
}