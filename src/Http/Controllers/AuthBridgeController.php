<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Http\Controllers;

use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\AuthBridge\Exceptions\InvalidStateException;
use Esanj\AuthBridge\Exceptions\TokenExchangeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Session;

class AuthBridgeController extends Controller
{
    private const SESSION_STATE_KEY = 'auth_bridge_state';
    private const SESSION_TOKEN_KEY = 'auth_bridge';

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

        $code = $request->input('code');

        $tokenData = $this->authBridgeService->exchangeAuthorizationCodeForAccessToken($code);

        Session::put(self::SESSION_TOKEN_KEY, $tokenData->toArray());


        return redirect()->to(config('esanj.auth_bridge.success_redirect', '/'));
    }


    /**
     * @throws InvalidStateException
     */
    private function validateState(Request $request): void
    {
        if (!app()->isProduction()) {
            return;
        }

        $storedState = $request->session()->pull(self::SESSION_STATE_KEY);
        $requestState = $request->input('state');

        if (empty($storedState)) {
            throw InvalidStateException::missing();
        }

        if ($storedState !== $requestState) {
            throw InvalidStateException::mismatch();
        }
    }
}
