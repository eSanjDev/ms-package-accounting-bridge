<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Events\Concerns;

use Illuminate\Support\Facades\Event;

trait Dispatchable
{
    public static function dispatch(mixed ...$args): mixed
    {
        return Event::dispatch(new static(...$args));
    }
}
