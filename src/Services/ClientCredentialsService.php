<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Services;

use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Esanj\AuthBridge\DTOs\TokenData;
use Esanj\AuthBridge\Events\TokenExchangeFailed;
use Esanj\AuthBridge\Events\TokenReceived;
use Esanj\AuthBridge\Exceptions\TokenRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClientCredentialsService implements ClientCredentialsServiceInterface
{
    private const OAUTH_TOKEN_PATH = '/oauth/token';
    private const CACHE_PREFIX = 'auth_bridge_cc_token_';
    private const CACHE_BUFFER_SECONDS = 60;
    private const DEFAULT_EXPIRES_IN = 3600;
    private const DEFAULT_SCOPE = '*';

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('esanj.auth_bridge.base_url', ''), '/');
    }

    public function getAccessToken(string $clientId, string $clientSecret, ?string $scope = null): TokenData
    {
        $cacheKey = $this->buildCacheKey($clientId, $scope);

        $cached = Cache::get($cacheKey);
        if ($cached instanceof TokenData && ! $cached->isExpired()) {
            return $cached;
        }

        return $this->requestAndCacheToken($clientId, $clientSecret, $scope, $cacheKey);
    }

    public function invalidateToken(string $clientId, ?string $scope = null): void
    {
        $cacheKey = $this->buildCacheKey($clientId, $scope);
        Cache::forget($cacheKey);
    }

    private function buildCacheKey(string $clientId, ?string $scope): string
    {
        $identifier = "{$clientId}_{$scope}";

        return self::CACHE_PREFIX . hash('sha256', $identifier);
    }

    private function requestAndCacheToken(
        string $clientId,
        string $clientSecret,
        ?string $scope,
        string $cacheKey
    ): TokenData {
        $tokenData = $this->requestToken($clientId, $clientSecret, $scope);

        $ttl = max($tokenData->expiresIn - self::CACHE_BUFFER_SECONDS, 1);
        Cache::put($cacheKey, $tokenData, $ttl);

        return $tokenData;
    }

    private function requestToken(string $clientId, string $clientSecret, ?string $scope): TokenData
    {
        try {
            $response = Http::asForm()->post($this->baseUrl . self::OAUTH_TOKEN_PATH, [
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
            $error = $response->json('error_description', $response->json('error', 'Unknown error'));
            $this->logError($clientId, $response->status(), $error);
            $exception = TokenRequestException::failed($clientId, $error, $response->status());
            TokenExchangeFailed::dispatch($exception, 'client_credentials');
            throw $exception;
        }

        $tokenData = TokenData::fromArray($response->json());
        TokenReceived::dispatch($tokenData, 'client_credentials');

        return $tokenData;
    }

    private function logError(string $clientId, int $status, string $error): void
    {
        Log::channel('emergency')->alert('OAuth client credentials failed', [
            'service' => self::class,
            'client_id' => $clientId,
            'status' => $status,
            'error' => $error,
        ]);
    }
}