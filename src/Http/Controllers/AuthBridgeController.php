<?php

namespace Esanj\AuthBridge\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuthBridgeController
{
    public function redirect(Request $request)
    {
        $request->session()->put('state', $state = Str::random(40));

        $query = http_build_query([
            'client_id' => config('auth_bridge.client_id'),
            'redirect_uri' => route(config('auth_bridge.redirect_route')),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
            'prompt' => config('auth_bridge.auth2_prompt'),
        ]);

        $oAuthBaseUrl = config('auth_bridge.base_url');
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

        $oAuthBaseUrl = config('auth_bridge.base_url');

        $response = Http::asForm()->post("{$oAuthBaseUrl}/oauth/token", [
            'grant_type' => 'authorization_code',
            'client_id' => config('auth_bridge.client_id'),
            'client_secret' => config('auth_bridge.client_secret'),
            'redirect_uri' => route(config('auth_bridge.redirect_route')),
            'code' => $request->code,
        ]);

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
            return redirect()->to(config('auth_bridge.success_redirect', '/'));
        } else {
            $message = $responseData['error_description'] ?? 'There was an issue processing the request.';
            abort(403, $message);
        }
    }
}
