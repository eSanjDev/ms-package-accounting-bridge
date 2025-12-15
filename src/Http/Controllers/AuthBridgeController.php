<?php

namespace Esanj\AuthBridge\Http\Controllers;

use Esanj\AuthBridge\Services\AuthBridgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuthBridgeController extends Controller
{
    public function __construct(
        private readonly AuthBridgeService $authBridgeService
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('state', $state);

        $config = config('esanj.auth_bridge');

        $query = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_url'],
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
            'prompt' => $config['auth2_prompt'],
        ]);

        return redirect("{$config['base_url']}/oauth/authorize?{$query}");
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('state');

        throw_unless(
            is_string($state) && strlen($state) > 0 && $state === $request->input('state'),
            InvalidArgumentException::class,
            'Invalid state value.'
        );

        $code = $request->input('code');
        $response = $this->authBridgeService->exchangeAuthorizationCodeForAccessToken($code);

        if (!$response->successful()) {
            $message = $response->json('error_description', 'There was an issue processing the request.');
            abort(403, $message);
        }

        $responseData = $response->json();

        Session::put('auth_bridge', [
            'access_token' => $responseData['access_token'],
            'refresh_token' => $responseData['refresh_token'] ?? null,
            'expires_in' => $responseData['expires_in'],
            'token_type' => $responseData['token_type'],
        ]);

        return redirect()->to(config('esanj.auth_bridge.success_redirect', '/'));
    }
}
