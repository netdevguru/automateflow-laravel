<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Exceptions;

/** The workspace plan does not include this capability (HTTP 403). */
class FeatureLimitException extends AutomateFlowException
{
    public function __construct(string $message, public readonly ?string $feature = null)
    {
        parent::__construct($message, 403);
    }
}
