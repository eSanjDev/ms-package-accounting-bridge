<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Events;

use Esanj\AuthBridge\DTOs\TokenData;
use Esanj\AuthBridge\Events\Concerns\Dispatchable;

class TokenReceived
{
    use Dispatchable;

    public function __construct(
        public readonly TokenData $tokenData,
        public readonly string $grantType = 'authorization_code',
    ) {}
}