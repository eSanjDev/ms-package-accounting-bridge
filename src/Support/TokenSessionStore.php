<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Support;

use Esanj\AuthBridge\DTOs\TokenData;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;

class TokenSessionStore
{
    public function key(): string
    {
        return (string) config('esanj.auth_bridge.session_token_key', 'auth_bridge');
    }

    public function put(TokenData $token): void
    {
        Session::put($this->key(), $token->toArray());
    }

    public function get(): ?TokenData
    {
        $data = Session::get($this->key());

        if (!is_array($data)) {
            return null;
        }

        try {
            return TokenData::fromStorage($data);
        } catch (InvalidArgumentException) {
            $this->forget();

            return null;
        }
    }

    public function raw(): ?array
    {
        $data = Session::get($this->key());

        return is_array($data) ? $data : null;
    }

    public function has(): bool
    {
        return $this->get() !== null;
    }

    public function forget(): void
    {
        Session::forget($this->key());
    }
}