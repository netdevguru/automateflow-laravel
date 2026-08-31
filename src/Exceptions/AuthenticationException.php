<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Exceptions;

/** The API key is missing, wrong, or expired (HTTP 401). Retrying will not help. */
class AuthenticationException extends AutomateFlowException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 401);
    }
}
