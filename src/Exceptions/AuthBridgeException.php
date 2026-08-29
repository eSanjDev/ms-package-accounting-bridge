<?php

declare(strict_types=1);

namespace Esanj\AuthBridge\Exceptions;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuthBridgeException extends Exception implements HttpExceptionInterface
{
    protected array $context = [];

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getStatusCode(): int
    {
        $code = $this->getCode();

        return $code >= 400 && $code <= 599 ? $code : 500;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
