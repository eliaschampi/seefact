<?php

declare(strict_types=1);

namespace See\Input;

use RuntimeException;

/**
 * A request the service refuses before Greenter or SUNAT see it.
 *
 * 400 means the payload does not match the contract shape; 422 means the
 * shape is right but the content cannot become a correct CPE (totals that
 * do not add up, an expired certificate). Neither succeeds if resent as-is.
 */
final class InvalidInput extends RuntimeException
{
    private function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly string $pointer,
    ) {
        parent::__construct($message);
    }

    public static function shape(string $pointer, string $message, string $code = 'invalid_input'): self
    {
        return new self(400, $code, $message, $pointer);
    }

    public static function content(string $pointer, string $code, string $message): self
    {
        return new self(422, $code, $message, $pointer);
    }
}
