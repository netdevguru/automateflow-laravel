<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Exceptions;

/**
 * The key's rate window is full (HTTP 429).
 *
 * The only failure here that is expected in normal operation: bulk work will meet it
 * routinely, and the correct response is to retry later, never to drop the record.
 * Queued jobs in this package release themselves back for exactly this reason.
 */
class ThrottledException extends AutomateFlowException
{
    public function __construct(string $message, public readonly ?string $window = null)
    {
        parent::__construct($message, 429);
    }
}
