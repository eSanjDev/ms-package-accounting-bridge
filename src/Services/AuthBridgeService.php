<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Services;

use Esanj\AuthBridge\Contracts\AuthBridgeServiceInterface;
use Esanj\AuthBridge\DTOs\AuthorizationRequest;
use Esanj\AuthBridge\DTOs\TokenData;
use Esanj\AuthBridge\Events\AuthorizationRedirecting;
use Esanj\AuthBridge\Events\TokenExchangeFailed;
use Esanj\AuthBridge\Events\TokenReceived;
use Esanj\AuthBridge\Exceptions\ConfigurationException;
use Esanj\AuthBridge\Exceptions\TokenExchangeException;
use Esanj\AuthBridge\Services\Concerns\ReadsOAuthError;
use Esanj\AuthBridge\Services\Concerns\ResolvesBaseUrl;
use Esanj\AuthBridge\Support\TokenSessionStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

class AuthBridgeService implements AuthBridgeServiceInterface
{
    use ReadsOAuthError;
    use ResolvesBaseUrl;

    private const OAUTH_TOKEN_PATH = '/oauth/token';
    private const OAUTH_AUTHORIZE_PATH = '/oauth/authorize';
    private const DEFAULT_REFRESH_BUFFER_SECONDS = 60;
    private const MAX_REFRESH_BUFFER_SECONDS = 300;
    private const REFRESH_LOCK_PREFIX = 'auth_bridge:refresh-lock:';
    private const REFRESH_LOCK_TTL_SECONDS = 35;
    private const REFRESH_LOCK_WAIT_SECONDS = 5;
    private const SHARED_TOKEN_PREFIX = 'auth_bridge:shared-token:';
    private const SHARED_TOKEN_TTL_SECONDS = 120;
    private const REVOKE_TIMEOUT_SECONDS = 5;
    private const TOKEN_CONNECT_TIMEOUT_SECONDS = 5;
    private const TOKEN_TIMEOUT_SECONDS = 10;
    private const GRANT_REJECTED_STATUSES = [400, 401];
    private const MAX_PENDING_STATES = 5;

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $defaultRedirectUrl;
    private string $prompt;
    private string $scope;
    private string $refreshTokenPath;
    private string $revokeTokenPath;
    private ?string $logChannel;
    private ?TokenData $memoizedToken = null;
    private bool $memoized = false;
    private int $refreshBufferSeconds;

    public function __construct(
        private readonly TokenSessionStore $store = new TokenSessionStore()
    )
    {
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $config = (array)(config('esanj.auth_bridge') ?? []);

        $this->baseUrl = $this->resolveBaseUrl();
        $this->clientId = (string)($config['client_id'] ?? '');
        $this->clientSecret = (string)($config['client_secret'] ?? '');

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw ConfigurationException::missingCredentials();
        }
        $this->defaultRedirectUrl = $this->resolveRedirectUrl($config);
        $this->prompt = $config['auth2_prompt'] ?? '';
        $this->scope = (string)($config['scope'] ?? '');
        $this->refreshTokenPath = $config['refresh_token_path'] ?? self::OAUTH_TOKEN_PATH;
        $this->revokeTokenPath = (string)($config['revoke_token_path'] ?? '');
        $this->logChannel = $config['log_channel'] ?? null;
        $buffer = (int)($config['refresh_buffer_seconds'] ?? self::DEFAULT_REFRESH_BUFFER_SECONDS);
        $this->refreshBufferSeconds = max(0, min($buffer, self::MAX_REFRESH_BUFFER_SECONDS));
    }

    private function resolveRedirectUrl(array $config): string
    {
        $redirectUrl = trim((string)($config['redirect_url'] ?? ''));

        if (!str_starts_with($redirectUrl, '/') || str_starts_with($redirectUrl, '//')) {
            return $redirectUrl;
        }

        return rtrim((string)config('app.url', ''), '/') . $redirectUrl;
    }

    public function buildAuthorizationUrl(): string
    {
        $state = Str::random(40);

        $request = new AuthorizationRequest(
            clientId: $this->getClientId(),
            redirectUri: $this->getRedirectUrl(),
            state: $state,
            scope: $this->getScope(),
            prompt: $this->getPrompt(),
        );

        $url = $this->getBaseUrl() . self::OAUTH_AUTHORIZE_PATH . "?" . $request->toQueryString();

        $this->rememberState($state);

        AuthorizationRedirecting::dispatch($request, $url);

        return $url;
    }

    private function rememberState(string $state): void
    {
        $key = config('esanj.auth_bridge.session_state_key');

        $states = self::pendingStates(Session::get($key));
        $states[] = $state;

        Session::put($key, array_slice($states, -self::MAX_PENDING_STATES));
    }

    public static function pendingStates(mixed $stored): array
    {
        if (is_string($stored)) {
            return $stored === '' ? [] : [$stored];
        }

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    public function exchangeAuthorizationCodeForAccessToken(string $code): TokenData
    {
        try {
            $response = $this->tokenRequest()->post($this->getBaseUrl() . self::OAUTH_TOKEN_PATH, [
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
            $error = $this->oauthError($response);
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
            $response = $this->tokenRequest()->post($this->getBaseUrl() . $this->refreshTokenPath, $payload);
        } catch (ConnectionException $e) {
            $exception = TokenExchangeException::connectionFailed($e->getMessage());
            TokenExchangeFailed::dispatch($exception, 'refresh_token');
            throw $exception;
        }

        if ($response->failed()) {
            $error = $this->oauthError($response);
            $exception = TokenExchangeException::failed($error, $response->status(), $this->safeContext($response));
            TokenExchangeFailed::dispatch($exception, 'refresh_token');
            throw $exception;
        }

        return $this->tokenFromResponse($response, 'refresh_token');
    }

    private function tokenRequest(): PendingRequest
    {
        return Http::asForm()
            ->acceptJson()
            ->connectTimeout(self::TOKEN_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TOKEN_TIMEOUT_SECONDS);
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
        $lock = $this->refreshLock($token);

        if ($lock === null) {
            return $this->performRefresh($token);
        }

        try {
            $lock->block(self::REFRESH_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return $this->adoptSharedToken($token) ?? ($token->isExpired() ? null : $token);
        }

        try {
            return $this->adoptSharedToken($token) ?? $this->performRefresh($token);
        } finally {
            $lock->release();
        }
    }

    private function performRefresh(TokenData $token): ?TokenData
    {
        try {
            $refreshed = $this->refreshAccessToken($token->refreshToken, $token->scope)
                ->carryForwardFrom($token);
        } catch (TokenExchangeException $e) {
            return $this->handleFailedRefresh($token, $e);
        }

        $this->store->put($refreshed);
        Cache::put($this->sharedTokenKey($token), $refreshed->toArray(), self::SHARED_TOKEN_TTL_SECONDS);

        return $refreshed;
    }

    private function handleFailedRefresh(TokenData $token, TokenExchangeException $e): ?TokenData
    {
        if (!$token->isExpired()) {
            return $token;
        }

        if (in_array($e->getCode(), self::GRANT_REJECTED_STATUSES, true)) {
            $this->clearToken();
        }

        return null;
    }

    private function adoptSharedToken(TokenData $spent): ?TokenData
    {
        $data = Cache::get($this->sharedTokenKey($spent));

        if (!is_array($data)) {
            return null;
        }

        try {
            $shared = TokenData::fromStorage($data);
        } catch (InvalidArgumentException) {
            Cache::forget($this->sharedTokenKey($spent));

            return null;
        }

        if ($shared->isExpiring($this->refreshBufferSeconds)) {
            return null;
        }

        $this->store->put($shared);

        return $shared;
    }

    // Keyed by the refresh token being spent: every request still holding it finds its replacement, whatever
    // the session id has become since.
    private function sharedTokenKey(TokenData $spent): string
    {
        return self::SHARED_TOKEN_PREFIX . hash('sha256', (string)$spent->refreshToken);
    }

    private function refreshLock(TokenData $token): ?Lock
    {
        if (!Cache::getStore() instanceof LockProvider) {
            return null;
        }

        return Cache::lock(
            self::REFRESH_LOCK_PREFIX . hash('sha256', (string)$token->refreshToken),
            self::REFRESH_LOCK_TTL_SECONDS
        );
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

        $this->memoizedToken = $tokenData;
        $this->memoized = true;
    }

    public function clearToken(): void
    {
        $token = $this->store->get();

        if ($token?->hasRefreshToken()) {
            Cache::forget($this->sharedTokenKey($token));
        }

        $this->store->forget();

        $this->memoizedToken = null;
        $this->memoized = true;
    }

    private function log(): LoggerInterface
    {
        return Log::channel($this->logChannel);
    }

    public function revokeToken(): void
    {
        $token = $this->store->get();

        try {
            if ($token !== null) {
                $this->revokeOnServer();
            }
        } finally {
            $this->clearToken();
        }
    }

    private function revokeOnServer(): void
    {
        if ($this->revokeTokenPath === '') {
            return;
        }

        try {
            $accessToken = $this->getValidAccessToken();

            if ($accessToken === null || $accessToken === '') {
                $this->log()->warning('Auth bridge: no valid access token left to revoke on the server', [
                    'path' => $this->revokeTokenPath,
                ]);

                return;
            }

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(self::TOKEN_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REVOKE_TIMEOUT_SECONDS)
                ->post($this->getBaseUrl() . $this->revokeTokenPath);
        } catch (Throwable $e) {
            $this->log()->warning('Auth bridge: token revocation could not be delivered', [
                'error' => $e->getMessage(),
                'path' => $this->revokeTokenPath,
            ]);
            TokenExchangeFailed::dispatch(TokenExchangeException::revocationFailed($e->getMessage()), 'revoke');

            return;
        }

        if ($response->failed()) {
            $this->log()->warning('Auth bridge: the OAuth server rejected the token revocation', [
                'status' => $response->status(),
                'path' => $this->revokeTokenPath,
            ]);
            TokenExchangeFailed::dispatch(
                TokenExchangeException::revocationFailed(
                    $this->oauthError($response),
                    $response->status(),
                    $this->safeContext($response)
                ),
                'revoke'
            );
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

    public function getScope(): string
    {
        return $this->scope;
    }
}
