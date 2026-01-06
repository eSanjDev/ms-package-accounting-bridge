<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Services;

use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\AuthBridge\DTOs\AuthorizationRequest;
use Esanj\AuthBridge\DTOs\TokenData;
use Esanj\AuthBridge\Events\AuthorizationRedirecting;
use Esanj\AuthBridge\Events\TokenExchangeFailed;
use Esanj\AuthBridge\Events\TokenReceived;
use Esanj\AuthBridge\Exceptions\TokenExchangeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthBridgeService implements AuthBridgeServiceInterface
{
    private const OAUTH_TOKEN_PATH = '/oauth/token';
    private const OAUTH_AUTHORIZE_PATH = '/oauth/authorize';

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $defaultRedirectUrl;
    private string $prompt;

    public function __construct()
    {
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $config = config('esanj.auth_bridge');

        $this->baseUrl = rtrim($config['base_url'] ?? '', '/');
        $this->clientId = $config['client_id'] ?? '';
        $this->clientSecret = $config['client_secret'] ?? '';
        $this->defaultRedirectUrl = $config['redirect_url'] ?? '';
        $this->prompt = $config['auth2_prompt'] ?? 'consent';
    }

    public function buildAuthorizationUrl(): string
    {
        $state = Str::random(40);

        $request = new AuthorizationRequest(
            clientId: $this->getClientId(),
            redirectUri: $this->getRedirectUrl(),
            state: $state,
            prompt: $this->getPrompt(),
        );

        $url = $this->getBaseUrl() . self::OAUTH_AUTHORIZE_PATH . "?" . $request->toQueryString();

        AuthorizationRedirecting::dispatch($request, $url);

        return $url;
    }

    public function exchangeAuthorizationCodeForAccessToken(string $code): TokenData
    {
        try {
            $response = Http::asForm()->post($this->getBaseUrl() . self::OAUTH_TOKEN_PATH, [
                'grant_type' => 'authorization_code',
                'client_id' => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'redirect_uri' => $this->getRedirectUrl(),
                'code' => $code,
            ]);
        } catch (ConnectionException $e) {
            $exception = TokenExchangeException::connectionFailed($e->getMessage());
            TokenExchangeFailed::dispatch($exception);
            throw $exception;
        }

        if ($response->failed()) {
            $error = $response->json('error_description', $response->json('error', 'Unknown error'));
            $exception = TokenExchangeException::failed($error, $response->status(), [
                'response' => $response->json(),
            ]);
            TokenExchangeFailed::dispatch($exception);
            throw $exception;
        }

        $tokenData = TokenData::fromArray($response->json());
        TokenReceived::dispatch($tokenData, 'authorization_code');

        return $tokenData;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function getRedirectUrl(): string
    {
        return $this->defaultRedirectUrl;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }
}
