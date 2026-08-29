<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Exceptions\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait RedirectsToLogin
{
    public function render(Request $request): ?Response
    {
        if ($request->expectsJson()) {
            return null;
        }

        if (!config('esanj.auth_bridge.redirect_on_failed_login', false)) {
            return null;
        }

        return redirect()->route('auth-bridge.redirect');
    }
}
