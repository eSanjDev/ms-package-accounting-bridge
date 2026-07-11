<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Http\Middleware;

use Closure;
use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefreshExpiredToken
{
    public function __construct(
        private readonly AuthBridgeServiceInterface $authBridge
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->authBridge->getValidToken();

        return $next($request);
    }
}