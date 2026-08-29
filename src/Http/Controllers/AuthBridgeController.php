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
    public function __construct(
        private readonly AuthBridgeServiceInterface $authBridgeService
    )
    {
    }

    /**
     * Redirect to OAuth authorization server.
     *
     * Query Parameters:
     * - callback_url: Custom callback URL (optional, overrides config)
     * - success_redirect: URL to redirect after successful authentication (optional)
     */
    public function redirect(): RedirectResponse
    {
        $authorizationUrl = $this->authBridgeService->buildAuthorizationUrl();

        return redirect($authorizationUrl);
    }

    /**
     * Handle OAuth callback from authorization server.
     * @throws InvalidStateException
     * @throws TokenExchangeException
     */
    public function callback(Request $request): RedirectResponse
    {
        $this->validateState($request);

        $code = $this->resolveCode($request);

        $tokenData = $this->authBridgeService->exchangeAuthorizationCodeForAccessToken($code);

        $request->session()->regenerate(true);

        $this->authBridgeService->storeToken($tokenData);

        return redirect()->to(config('esanj.auth_bridge.success_redirect', '/'));
    }


    /**
     * The server sends back `error`/`error_description` instead of `code` when the
     * user denies consent or the request is rejected.
     *
     * @throws TokenExchangeException
     */
    private function resolveCode(Request $request): string
    {
        $code = $request->input('code');

        if (is_string($code) && $code !== '') {
            return $code;
        }

        $error = $request->input('error_description', $request->input('error'));

        throw TokenExchangeException::failed(
            is_string($error) && $error !== '' ? $error : 'Authorization code is missing'
        );
    }


    /**
     * @throws InvalidStateException
     */
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
