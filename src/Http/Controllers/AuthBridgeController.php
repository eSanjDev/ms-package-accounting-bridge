<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Http\Controllers;

use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\AuthBridge\Exceptions\InvalidStateException;
use Esanj\AuthBridge\Exceptions\TokenExchangeException;
use Esanj\AuthBridge\Services\AuthBridgeService;
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

        $key = config('esanj.auth_bridge.session_state_key');
        $states = AuthBridgeService::pendingStates($request->session()->get($key));

        if ($states === []) {
            throw InvalidStateException::missing();
        }

        $requestState = $request->input('state');
        $matched = is_string($requestState) ? array_search($requestState, $states, true) : false;

        if ($matched === false) {
            $request->session()->forget($key);

            throw InvalidStateException::mismatch();
        }

        unset($states[$matched]);

        $states === []
            ? $request->session()->forget($key)
            : $request->session()->put($key, array_values($states));
    }
}
