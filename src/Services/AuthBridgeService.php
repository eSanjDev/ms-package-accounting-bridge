<?php

namespace Esanj\AuthBridge\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AuthBridgeService
{
    /**
     * @throws ConnectionException
     */
    public function exchangeAuthorizationCodeForAccessToken(string $code): Response
    {
        $config = config('esanj.auth_bridge');

        return Http::asForm()->post("{$config['base_url']}/oauth/token", [
            'grant_type' => 'authorization_code',
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_url'],
            'code' => $code,
        ]);
    }
}
