<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Services\Concerns;

use Illuminate\Http\Client\Response;

trait ReadsOAuthError
{
    private function oauthError(Response $response): string
    {
        foreach (['error_description', 'error'] as $field) {
            $value = $response->json($field);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'Unknown error';
    }
}
