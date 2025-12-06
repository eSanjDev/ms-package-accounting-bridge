<?php

namespace Esanj\AuthBridge\Http\Controllers;

use Esanj\AuthBridge\Services\AuthBridgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuthBridgeController
{
    public function __construct(protected AuthBridgeService $authBridgeService)
    {
    }

    public function redirect(Request $request)
    {
        $request->session()->put('state', $state = Str::random(40));

        $query = http_build_query([
            'client_id' => config('esanj.auth_bridge.client_id'),
            'redirect_uri' => config('esanj.uth_bridge.redirect_url'),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
            'prompt' => config('esanj.auth_bridge.auth2_prompt'),
        ]);

        $oAuthBaseUrl = config('esanj.auth_bridge.base_url');
        return redirect("{$oAuthBaseUrl}/oauth/authorize?" . $query);
    }

    public function callback(Request $request)
    {
        $state = $request->session()->pull('state');

        throw_unless(
            strlen($state) > 0 && $state === $request->state,
            InvalidArgumentException::class,
            'Invalid state value.'
        );

        $response = $this->authBridgeService->exchangeAuthorizationCodeForAccessToken($request->get('code'));

        $responseData = $response->json();

        if ($response->successful()) {
            Session::put([
                'auth_bridge' => [
                    'access_token' => $responseData['access_token'],
                    'refresh_token' => $responseData['refresh_token'],
                    'expires_in' => $responseData['expires_in'],
                    'token_type' => $responseData['token_type'],
                ]
            ]);
            return redirect()->to(config('esanj.auth_bridge.success_redirect', '/'));
        } else {
            $message = $responseData['error_description'] ?? 'There was an issue processing the request.';
            abort(403, $message);
        }
    }
}
