<?php

namespace Esanj\AuthBridge\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClientCredentialsService
{
    private const CACHE_PREFIX = 'auth_bridge_cc_token_';
    private const CACHE_BUFFER_SECONDS = 60;
    private const DEFAULT_EXPIRES_IN = 3600;

    /**
     * @throws ConnectionException
     */
    public function getAccessToken(string $clientId, string $clientSecret, ?string $scope = null): ?array
    {
        $cacheKey = $this->buildCacheKey($clientId, $scope);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $tokenData = $this->requestToken($clientId, $clientSecret, $scope);

        if ($tokenData === null) {
            return null;
        }

        $ttl = ($tokenData['expires_in'] ?? self::DEFAULT_EXPIRES_IN) - self::CACHE_BUFFER_SECONDS;
        Cache::put($cacheKey, $tokenData, max($ttl, 1));

        return $tokenData;
    }

    private function buildCacheKey(string $clientId, ?string $scope): string
    {
        return self::CACHE_PREFIX . hash('sha256', "{$clientId}_{$scope}");
    }

    /**
     * @throws ConnectionException
     */
    private function requestToken(string $clientId, string $clientSecret, ?string $scope): ?array
    {
        $baseUrl = config('esanj.auth_bridge.base_url');

        $response = Http::asForm()->post("{$baseUrl}/oauth/token", [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => $scope ?? '*',
        ]);

        if ($response->failed()) {
            Log::channel('emergency')->alert('OAuth client credentials failed', [
                'service' => 'ClientCredentialsService',
                'client_id' => $clientId,
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);
            return null;
        }

        return $response->json();
    }
}
