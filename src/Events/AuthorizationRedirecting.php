<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Events;

use Esanj\AuthBridge\DTOs\AuthorizationRequest;
use Illuminate\Foundation\Events\Dispatchable;

class AuthorizationRedirecting
{
    use Dispatchable;

    public function __construct(
        public readonly AuthorizationRequest $request,
        public readonly string $authorizationUrl,
    ) {}
}