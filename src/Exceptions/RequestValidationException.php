<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Exceptions;

/**
 * The API rejected the payload (HTTP 422).
 *
 * Named RequestValidationException rather than ValidationException so it cannot be
 * confused with Illuminate's, which the framework treats specially in exception
 * rendering — an accidental import there turns an API problem into a 422 response
 * to the end user with someone else's field names in it.
 */
class RequestValidationException extends AutomateFlowException
{
    /** @param array<string, array<int, string>> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message, 422);
    }
}
