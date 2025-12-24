<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\DTOs;

final class AuthorizationRequest
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly string $state,
        public readonly string $responseType = 'code',
        public readonly string $scope = '',
        public readonly string $prompt = 'consent',
        public readonly ?string $successRedirect = null,
    ) {}

    public function toQueryString(): string
    {
        return http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => $this->responseType,
            'scope' => $this->scope,
            'state' => $this->state,
            'prompt' => $this->prompt,
        ]);
    }
}