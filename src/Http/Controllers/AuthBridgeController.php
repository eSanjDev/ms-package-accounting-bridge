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
use Illuminate\Support\Str;

class AuthBridgeController extends Controller
{
    private const SESSION_STATE_KEY = 'auth_bridge_state';
    private const SESSION_CALLBACK_KEY = 'auth_bridge_callback_url';
    private const SESSION_SUCCESS_REDIRECT_KEY = 'auth_bridge_success_redirect';
    private const SESSION_TOKEN_KEY = 'auth_bridge';

    public function __construct(
        private readonly AuthBridgeServiceInterface $authBridgeService
    ) {}

    /**
     * Redirect to OAuth authorization server.
     *
     * Query Parameters:
     * - callback_url: Custom callback URL (optional, overrides config)
     * - success_redirect: URL to redirect after successful authentication (optional)
     */
    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $callbackUrl = $this->resolveCallbackUrl($request);
        $successRedirect = $request->query('success_redirect');

        $this->storeSessionData($request, $state, $callbackUrl, $successRedirect);

        $authorizationUrl = $this->authBridgeService->buildAuthorizationUrl($state, $callbackUrl);

        return redirect($authorizationUrl);
    }

    /**
     * Handle OAuth callback from authorization server.
     */
    public function callback(Request $request): RedirectResponse
    {
        $this->validateState($request);

        $code = $request->input('code');
        $callbackUrl = $request->session()->pull(self::SESSION_CALLBACK_KEY);
        $successRedirect = $request->session()->pull(self::SESSION_SUCCESS_REDIRECT_KEY);

        try {
            $tokenData = $this->authBridgeService->exchangeAuthorizationCodeForAccessToken($code, $callbackUrl);
        } catch (TokenExchangeException $e) {
            abort($e->getCode() ?: 403, $e->getMessage());
        }

        Session::put(self::SESSION_TOKEN_KEY, $tokenData->toArray());

        $redirectUrl = $successRedirect ?? config('esanj.auth_bridge.success_redirect', '/');

        return redirect()->to($redirectUrl);
    }

    private function resolveCallbackUrl(Request $request): ?string
    {
        $callbackUrl = $request->query('callback_url');

        if ($callbackUrl !== null && $callbackUrl !== '') {
            return $callbackUrl;
        }

        return null;
    }

    private function storeSessionData(
        Request $request,
        string $state,
        ?string $callbackUrl,
        ?string $successRedirect
    ): void {
        $session = $request->session();

        $session->put(self::SESSION_STATE_KEY, $state);

        if ($callbackUrl !== null) {
            $session->put(self::SESSION_CALLBACK_KEY, $callbackUrl);
        }

        if ($successRedirect !== null) {
            $session->put(self::SESSION_SUCCESS_REDIRECT_KEY, $successRedirect);
        }
    }

    private function validateState(Request $request): void
    {
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