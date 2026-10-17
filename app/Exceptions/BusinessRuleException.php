<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that is well-formed but breaks a business rule (not enough
 * stock, credit limit exceeded, shift already closed ...). Rendered as a 422
 * with a stable error code so the UI and API clients can react to it.
 */
class BusinessRuleException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'business_rule',
        public readonly array $details = [],
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}
