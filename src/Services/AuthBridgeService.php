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
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Throwable;

class AuthBridgeService implements AuthBridgeServiceInterface
{
    private const OAUTH_TOKEN_PATH = '/oauth/token';
    private const OAUTH_AUTHORIZE_PATH = '/oauth/authorize';
    private const DEFAULT_REFRESH_BUFFER_SECONDS = 60;
    private const MAX_REFRESH_BUFFER_SECONDS = 300;
    private const REFRESH_LOCK_PREFIX = 'auth_bridge:refresh-lock:';
    private const REFRESH_LOCK_TTL_SECONDS = 35;
    private const REFRESH_LOCK_WAIT_SECONDS = 5;
    private const SHARED_TOKEN_PREFIX = 'auth_bridge:shared-token:';
    private const REVOKE_TIMEOUT_SECONDS = 5;

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $defaultRedirectUrl;
    private string $prompt;
    private string $refreshTokenPath;
    private string $revokeTokenPath;
    private ?TokenData $memoizedToken = null;
    private bool $memoized = false;
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
        $this->revokeTokenPath = (string) ($config['revoke_token_path'] ?? '');
        $buffer = (int) ($config['refresh_buffer_seconds'] ?? self::DEFAULT_REFRESH_BUFFER_SECONDS);
        $this->refreshBufferSeconds = max(0, min($buffer, self::MAX_REFRESH_BUFFER_SECONDS));
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
            $exception = TokenExchangeException::failed($error, $response->status(), $this->safeContext($response));
            TokenExchangeFailed::dispatch($exception);
            throw $exception;
        }

        return $this->tokenFromResponse($response, 'authorization_code');
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
            $exception = TokenExchangeException::failed($error, $response->status(), $this->safeContext($response));
            TokenExchangeFailed::dispatch($exception, 'refresh_token');
            throw $exception;
        }

        return $this->tokenFromResponse($response, 'refresh_token');
    }

    private function safeContext(Response $response): array
    {
        $context = [
            'status' => $response->status(),
            'content_type' => $response->header('Content-Type'),
        ];

        $body = $response->json();

        if (is_array($body)) {
            $context['error'] = array_filter(
                Arr::only($body, ['error', 'error_description', 'error_uri', 'hint']),
                'is_scalar'
            );
        }

        return $context;
    }

    private function tokenFromResponse(Response $response, string $grantType): TokenData
    {
        $payload = $response->json();

        if (!is_array($payload) || !isset($payload['access_token']) || !is_string($payload['access_token'])) {
            $exception = TokenExchangeException::malformedResponse($this->safeContext($response));
            TokenExchangeFailed::dispatch($exception, $grantType);
            throw $exception;
        }

        $tokenData = TokenData::fromArray($payload);
        TokenReceived::dispatch($tokenData, $grantType);

        return $tokenData;
    }

    public function getValidToken(): ?TokenData
    {
        if ($this->hasUsableMemo()) {
            return $this->memoizedToken;
        }

        $this->memoizedToken = $this->resolveValidToken();
        $this->memoized = true;

        return $this->memoizedToken;
    }

    private function hasUsableMemo(): bool
    {
        if (!$this->memoized) {
            return false;
        }

        return $this->memoizedToken === null
            || !$this->memoizedToken->isExpiring($this->refreshBufferSeconds);
    }

    private function resolveValidToken(): ?TokenData
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

        return $this->refreshUnderLock($token);
    }

    private function refreshUnderLock(TokenData $token): ?TokenData
    {
        $lock = $this->refreshLock();

        if ($lock === null) {
            return $this->performRefresh($token);
        }

        try {
            $lock->block(self::REFRESH_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return $this->adoptSharedToken() ?? ($token->isExpired() ? null : $token);
        }

        try {
            return $this->adoptSharedToken() ?? $this->performRefresh($token);
        } finally {
            $lock->release();
        }
    }

    private function performRefresh(TokenData $token): ?TokenData
    {
        try {
            $refreshed = $this->refreshAccessToken($token->refreshToken, $token->scope);
        } catch (TokenExchangeException $e) {
            return $this->handleFailedRefresh($token, $e);
        }

        $this->store->put($refreshed);
        $this->shareToken($refreshed);

        return $refreshed;
    }

    private function handleFailedRefresh(TokenData $token, TokenExchangeException $e): ?TokenData
    {
        if (!$token->isExpired()) {
            return $token;
        }

        if ($e->getCode() >= 400 && $e->getCode() < 500) {
            $this->clearToken();
        }

        return null;
    }

    private function adoptSharedToken(): ?TokenData
    {
        $data = Cache::get($this->sharedTokenKey());

        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        $shared = TokenData::fromStorage($data);

        if ($shared->isExpiring($this->refreshBufferSeconds)) {
            return null;
        }

        $this->store->put($shared);

        return $shared;
    }

    private function shareToken(TokenData $token): void
    {
        Cache::put($this->sharedTokenKey(), $token->toArray(), max(1, $token->expiresIn));
    }

    private function sharedTokenKey(): string
    {
        return self::SHARED_TOKEN_PREFIX . Session::getId();
    }

    private function refreshLock(): ?Lock
    {
        if (!Cache::getStore() instanceof LockProvider) {
            return null;
        }

        return Cache::lock(self::REFRESH_LOCK_PREFIX . Session::getId(), self::REFRESH_LOCK_TTL_SECONDS);
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
        $this->shareToken($tokenData);

        $this->memoizedToken = $tokenData;
        $this->memoized = true;
    }

    public function clearToken(): void
    {
        $this->store->forget();
        Cache::forget($this->sharedTokenKey());

        $this->memoizedToken = null;
        $this->memoized = true;
    }

    public function revokeToken(): void
    {
        $token = $this->store->get();

        if ($token !== null) {
            $this->revokeOnServer($token);
        }

        $this->clearToken();
    }

    private function revokeOnServer(TokenData $token): void
    {
        if ($this->revokeTokenPath === '') {
            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::REVOKE_TIMEOUT_SECONDS)
                ->post($this->getBaseUrl() . $this->revokeTokenPath, [
                    'client_id' => $this->getClientId(),
                    'client_secret' => $this->getClientSecret(),
                    'token' => $token->refreshToken ?? $token->accessToken,
                    'token_type_hint' => $token->hasRefreshToken() ? 'refresh_token' : 'access_token',
                ]);

            if ($response->failed()) {
                Log::warning('Auth bridge: the OAuth server rejected the token revocation', [
                    'status' => $response->status(),
                    'path' => $this->revokeTokenPath,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Auth bridge: token revocation could not be delivered', [
                'error' => $e->getMessage(),
                'path' => $this->revokeTokenPath,
            ]);
        }
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
