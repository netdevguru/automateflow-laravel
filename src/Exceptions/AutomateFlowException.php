<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Exceptions;

use RuntimeException;

/**
 * Base for every failure the API reports.
 *
 * Subclassed rather than carrying a status code because callers act differently on
 * each kind, and `catch (ThrottledException $e)` states that intent where
 * `if ($e->status === 429)` merely implements it.
 */
class AutomateFlowException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }
}
