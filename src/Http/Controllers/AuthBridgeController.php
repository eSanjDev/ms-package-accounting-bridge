<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Http\Controllers;

use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\AuthBridge\Exceptions\InvalidStateException;
use Esanj\AuthBridge\Exceptions\TokenExchangeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AuthBridgeController extends Controller
{
    private const OAUTH_ERROR_CODES = [
        'invalid_request',
        'unauthorized_client',
        'access_denied',
        'unsupported_response_type',
        'invalid_scope',
        'server_error',
        'temporarily_unavailable',
    ];

    public function __construct(
        private readonly AuthBridgeServiceInterface $authBridgeService
    )
    {
    }

    public function redirect(): RedirectResponse
    {
        $authorizationUrl = $this->authBridgeService->buildAuthorizationUrl();

        return redirect($authorizationUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->validateState($request);

        $code = $this->resolveCode($request);

        $tokenData = $this->authBridgeService->exchangeAuthorizationCodeForAccessToken($code);

        $request->session()->regenerate(true);

        $this->authBridgeService->storeToken($tokenData);

        return redirect()->to(config('esanj.auth_bridge.success_redirect', '/'));
    }

    private function resolveCode(Request $request): string
    {
        $code = $request->input('code');

        if (is_string($code) && $code !== '') {
            return $code;
        }

        $error = $request->input('error');

        throw TokenExchangeException::authorizationFailed(
            is_string($error) && in_array($error, self::OAUTH_ERROR_CODES, true) ? $error : null,
            array_filter([
                'error' => $request->input('error'),
                'error_description' => $request->input('error_description'),
            ], 'is_scalar')
        );
    }

    private function validateState(Request $request): void
    {
        if (!app()->isProduction()) {
            return;
        }

        $storedState = $request->session()->pull(config('esanj.auth_bridge.session_state_key'));
        $requestState = $request->input('state');

        if (empty($storedState)) {
            throw InvalidStateException::missing();
        }

        if ($storedState !== $requestState) {
            throw InvalidStateException::mismatch();
        }
    }
}
