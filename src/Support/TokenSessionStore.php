<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Support;

use Esanj\AuthBridge\DTOs\TokenData;
use Illuminate\Support\Facades\Session;

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

        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        return TokenData::fromStorage($data);
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