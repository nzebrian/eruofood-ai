<?php

declare(strict_types=1);

namespace EruoFood\Sdk;

/** Thrown for any non-2xx response; carries the standard error envelope fields. */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }
}
