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
use Esanj\AuthBridge\Support\TokenSessionStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class AuthBridgeService implements AuthBridgeServiceInterface
{
    private const OAUTH_TOKEN_PATH = '/oauth/token';
    private const OAUTH_AUTHORIZE_PATH = '/oauth/authorize';
    private const DEFAULT_REFRESH_BUFFER_SECONDS = 60;

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $defaultRedirectUrl;
    private string $prompt;
    private string $refreshTokenPath;
    private int $refreshBufferSeconds;

    public function __construct(
        private readonly TokenSessionStore $store = new TokenSessionStore()
    ) {
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
        $this->refreshTokenPath = $config['refresh_token_path'] ?? self::OAUTH_TOKEN_PATH;
        $this->refreshBufferSeconds = (int) ($config['refresh_buffer_seconds'] ?? self::DEFAULT_REFRESH_BUFFER_SECONDS);
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

        Session::put(config('esanj.auth_bridge.session_state_key'), $state);

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

    public function refreshAccessToken(string $refreshToken, ?string $scope = null): TokenData
    {
        $payload = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
        ];

        if ($scope !== null && $scope !== '') {
            $payload['scope'] = $scope;
        }

        try {
            $response = Http::asForm()->post($this->getBaseUrl() . $this->refreshTokenPath, $payload);
        } catch (ConnectionException $e) {
            $exception = TokenExchangeException::connectionFailed($e->getMessage());
            TokenExchangeFailed::dispatch($exception, 'refresh_token');
            throw $exception;
        }

        if ($response->failed()) {
            $error = $response->json('error_description', $response->json('error', 'Unknown error'));
            $exception = TokenExchangeException::failed($error, $response->status(), [
                'response' => $response->json(),
            ]);
            TokenExchangeFailed::dispatch($exception, 'refresh_token');
            throw $exception;
        }

        $tokenData = TokenData::fromArray($response->json());
        TokenReceived::dispatch($tokenData, 'refresh_token');

        return $tokenData;
    }

    public function getValidToken(): ?TokenData
    {
        $token = $this->store->get();

        if ($token === null) {
            return null;
        }

        if (!$token->isExpiring($this->refreshBufferSeconds)) {
            return $token;
        }

        if (!$token->hasRefreshToken()) {
            return $token->isExpired() ? null : $token;
        }

        try {
            $refreshed = $this->refreshAccessToken($token->refreshToken, $token->scope);
        } catch (TokenExchangeException) {
            $this->store->forget();

            return null;
        }

        $this->store->put($refreshed);

        return $refreshed;
    }

    public function getValidAccessToken(): ?string
    {
        return $this->getValidToken()?->accessToken;
    }

    public function getValidAuthorizationHeader(): ?string
    {
        return $this->getValidToken()?->getAuthorizationHeader();
    }

    public function storeToken(TokenData $tokenData): void
    {
        $this->store->put($tokenData);
    }

    public function clearToken(): void
    {
        $this->store->forget();
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
