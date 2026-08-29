<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\DTOs;

final readonly class AuthorizationRequest
{
    public function __construct(
        public string  $clientId,
        public string  $redirectUri,
        public string  $state,
        public string  $responseType = 'code',
        public string  $scope = '',
        public string  $prompt = 'consent',
        public ?string $successRedirect = null,
    ) {}

    public function toQueryString(): string
    {
        $params = array_filter([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => $this->responseType,
            'scope' => $this->scope,
            'state' => $this->state,
            'prompt' => $this->prompt,
        ], static fn (string $value): bool => $value !== '');

        return http_build_query($params);
    }
}
