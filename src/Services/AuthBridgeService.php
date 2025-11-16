<?php

namespace Esanj\AuthBridge\Services;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AuthBridgeService
{
    public function exchangeAuthorizationCodeForAccessToken(string $code): PromiseInterface|Response
    {
        $oAuthBaseUrl = config('auth_bridge.base_url');

        return Http::asForm()->post("{$oAuthBaseUrl}/oauth/token", [
            'grant_type' => 'authorization_code',
            'client_id' => config('auth_bridge.client_id'),
            'client_secret' => config('auth_bridge.client_secret'),
            'redirect_uri' => route(config('auth_bridge.redirect_route')),
            'code' => $code,
        ]);
    }

}
