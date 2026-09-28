<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Services;

use DomainException;
use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Esanj\AuthBridge\DTOs\TokenData;
use Esanj\AuthBridge\Events\TokenExchangeFailed;
use Esanj\AuthBridge\Events\TokenReceived;
use Esanj\AuthBridge\Exceptions\ExtractJWTException;
use Esanj\AuthBridge\Exceptions\TokenRequestException;
use Esanj\AuthBridge\Services\Concerns\ReadsOAuthError;
use Esanj\AuthBridge\Services\Concerns\ResolvesBaseUrl;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use stdClass;
use UnexpectedValueException;

class ClientCredentialsService implements ClientCredentialsServiceInterface
{
    use ReadsOAuthError;
    use ResolvesBaseUrl;

    private const OAUTH_TOKEN_PATH = '/oauth/token';
    private const CACHE_PREFIX = 'auth_bridge_cc_token_';
    private const CACHE_BUFFER_SECONDS = 60;
    private const DEFAULT_EXPIRES_IN = 3600;
    private const DEFAULT_SCOPE = '*';
    private const TOKEN_CONNECT_TIMEOUT_SECONDS = 5;
    private const TOKEN_TIMEOUT_SECONDS = 10;
    private const LOCK_SUFFIX = ':lock';
    private const LOCK_TTL_SECONDS = 20;
    private const LOCK_WAIT_SECONDS = 5;

    private string $baseUrl;
    private ?string $publicKey = null;

    public function __construct()
    {
        $this->baseUrl = $this->resolveBaseUrl();
    }

    public function getAccessToken(string $clientId, string $clientSecret, ?string $scope = null): TokenData
    {
        $cacheKey = $this->buildCacheKey($clientId, $clientSecret, $scope);

        return $this->cachedToken($cacheKey)
            ?? $this->requestUnderLock($clientId, $clientSecret, $scope, $cacheKey);
    }

    // Stored as an array: objects come back as __PHP_Incomplete_Class under cache.serializable_classes=false.
    private function cachedToken(string $cacheKey): ?TokenData
    {
        $cached = Cache::get($cacheKey);
        if (!is_array($cached)) {
            return null;
        }

        try {
            $token = TokenData::fromStorage($cached);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $token->isExpired() ? null : $token;
    }

    private function requestUnderLock(string $clientId, string $clientSecret, ?string $scope, string $cacheKey): TokenData
    {
        if (!Cache::getStore() instanceof LockProvider) {
            return $this->requestAndCacheToken($clientId, $clientSecret, $scope, $cacheKey);
        }

        $lock = Cache::lock($cacheKey . self::LOCK_SUFFIX, self::LOCK_TTL_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $lock = null;
        }

        try {
            return $this->cachedToken($cacheKey)
                ?? $this->requestAndCacheToken($clientId, $clientSecret, $scope, $cacheKey);
        } finally {
            $lock?->release();
        }
    }

    public function invalidateToken(string $clientId, string $clientSecret, ?string $scope = null): void
    {
        $cacheKey = $this->buildCacheKey($clientId, $clientSecret, $scope);
        Cache::forget($cacheKey);
    }

    private function buildCacheKey(string $clientId, string $clientSecret, ?string $scope): string
    {
        $identifier = json_encode([$this->baseUrl, $clientId, $clientSecret, $scope]);

        return self::CACHE_PREFIX . hash('sha256', $identifier);
    }

    private function requestAndCacheToken(
        string  $clientId,
        string  $clientSecret,
        ?string $scope,
        string  $cacheKey
    ): TokenData
    {
        $tokenData = $this->requestToken($clientId, $clientSecret, $scope);

        $ttl = max($tokenData->expiresIn - self::CACHE_BUFFER_SECONDS, 1);
        Cache::put($cacheKey, $tokenData->toArray(), $ttl);

        return $tokenData;
    }

    private function requestToken(string $clientId, string $clientSecret, ?string $scope): TokenData
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(self::TOKEN_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TOKEN_TIMEOUT_SECONDS)
                ->post($this->baseUrl . self::OAUTH_TOKEN_PATH, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => $scope ?? self::DEFAULT_SCOPE,
                ]);
        } catch (ConnectionException $e) {
            $this->logError($clientId, 0, $e->getMessage());
            $exception = TokenRequestException::connectionFailed($clientId, $e->getMessage());
            TokenExchangeFailed::dispatch($exception, 'client_credentials');
            throw $exception;
        }

        if ($response->failed()) {
            $error = $this->oauthError($response);
            $this->logError($clientId, $response->status(), $error);
            $exception = TokenRequestException::failed($clientId, $error, $response->status());
            TokenExchangeFailed::dispatch($exception, 'client_credentials');
            throw $exception;
        }

        $payload = $response->json();

        if (!is_array($payload) || !isset($payload['access_token']) || !is_string($payload['access_token'])) {
            $this->logError($clientId, $response->status(), 'Malformed token response (no access_token)');
            $exception = TokenRequestException::malformedResponse($clientId, $response->status());
            TokenExchangeFailed::dispatch($exception, 'client_credentials');
            throw $exception;
        }

        $tokenData = TokenData::fromArray($payload);
        TokenReceived::dispatch($tokenData, 'client_credentials');

        return $tokenData;
    }

    private function logError(string $clientId, int $status, string $error): void
    {
        Log::channel(config('esanj.auth_bridge.log_channel'))
            ->error('OAuth client credentials request failed', [
                'service' => self::class,
                'client_id' => $clientId,
                'status' => $status,
                'error' => $error,
            ]);
    }


    /**
     * @param string $jwt
     * @return stdClass
     * @throws ExtractJWTException
     */
    public function extractJwt(string $jwt): stdClass
    {
        try {
            $decoded = JWT::decode($jwt, new Key($this->publicKey(), 'RS256'));
        } catch (DomainException|InvalidArgumentException|UnexpectedValueException $e) {
            throw ExtractJWTException::invalidToken($e->getMessage());
        }

        $this->assertAudience($decoded);
        $this->assertIssuer($decoded);

        return $decoded;
    }

    private function publicKey(): string
    {
        if ($this->publicKey !== null) {
            return $this->publicKey;
        }

        $inline = (string)(config('esanj.auth_bridge.public_key') ?? '');

        if (trim($inline) !== '') {
            if (!str_contains($inline, 'BEGIN PUBLIC KEY')) {
                throw ExtractJWTException::publicKeyUnusable('the configured public_key is not a PEM block');
            }

            return $this->publicKey = $inline;
        }

        $path = (string)(config('esanj.auth_bridge.public_key_path') ?? '');

        if ($path === '' || !is_readable($path)) {
            throw ExtractJWTException::publicKeyNotFound($path);
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            throw ExtractJWTException::publicKeyUnusable('the key file is empty');
        }

        return $this->publicKey = $contents;
    }

    private function assertAudience(stdClass $decoded): void
    {
        $expected = config('esanj.auth_bridge.expected_audiences')
            ?: array_filter([(string)config('esanj.auth_bridge.client_id')]);

        if ($expected === []) {
            throw ExtractJWTException::invalidToken(
                'No expected audience is configured. Set ACCOUNTING_BRIDGE_CLIENT_ID or ACCOUNTING_BRIDGE_EXPECTED_AUDIENCE.'
            );
        }

        $aud = $decoded->aud ?? null;
        $audiences = array_map('strval', array_filter(is_array($aud) ? $aud : [$aud], 'is_scalar'));

        if (array_intersect($expected, $audiences) === []) {
            throw ExtractJWTException::invalidToken('Token audience does not match this client.', [
                'aud' => $aud,
                'expected' => array_values($expected),
            ]);
        }
    }

    private function assertIssuer(stdClass $decoded): void
    {
        $expected = config('esanj.auth_bridge.expected_issuer');

        if (empty($expected)) {
            return;
        }

        if (($decoded->iss ?? null) !== $expected) {
            throw ExtractJWTException::invalidToken('Token issuer is not trusted.', [
                'iss' => $decoded->iss ?? null,
                'expected' => $expected,
            ]);
        }
    }
}
