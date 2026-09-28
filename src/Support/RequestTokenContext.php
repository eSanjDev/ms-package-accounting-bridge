<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Support;

use Closure;
use Esanj\AuthBridge\DTOs\TokenData;

final class RequestTokenContext
{
    private bool $active = false;

    private ?TokenData $token = null;

    public function isActive(): bool
    {
        return $this->active;
    }

    public function token(): ?TokenData
    {
        return $this->token;
    }

    public function run(?TokenData $token, Closure $callback): mixed
    {
        $previousActive = $this->active;
        $previousToken = $this->token;
        $this->active = true;
        $this->token = $token;

        try {
            return $callback();
        } finally {
            $this->active = $previousActive;
            $this->token = $previousToken;
        }
    }
}
