<?php

namespace Esanj\AuthBridge\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manages OAuth2 authentication using Client Credentials Grant.
 */
class ClientCredentialsService
{
    /**
     * Retrieve an OAuth2 token dynamically based on the provided client credentials.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param ?string $scope
     * @return array|null
     * @throws ConnectionException
     */
    public function getAccessToken(string $clientId, string $clientSecret, string $scope = null): ?array
    {
        $cacheKey = md5("client_credentials_token_{$clientId}_{$clientSecret}_{$scope}");

        // Check if a valid token exists in cache
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Request a new token from the Accounting microservice
        $response = Http::asForm()->post(config('auth_bridge.base_url') . '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => $scope ?? '*',
        ]);

        if ($response->failed()) {
            Log::channel('emergency')->alert('fail oauth / auth-bridge / client credentials', [
                'service' => 'ClientCredentialsService',
                'json' => $response->json(),
                'status' => $response->getStatusCode(),
            ]);
            return null;
        }

        $tokenData = $response->json();
        $expiresIn = $tokenData['expires_in'] ?? 3600;

        // Store the token in cache with a small buffer before expiration
        Cache::put($cacheKey, $tokenData, $expiresIn - 60);

        return $tokenData;
    }
}
