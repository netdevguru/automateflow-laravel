<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Console;

use AutomateFlow\Laravel\Client;
use AutomateFlow\Laravel\Exceptions\AutomateFlowException;
use Illuminate\Console\Command;

/**
 * Verifies the credentials without changing anything.
 *
 * The first thing to run after configuring the package, and the first thing to run when
 * something stops working — it separates "the key is wrong" from "the app is wrong",
 * which are otherwise indistinguishable from a queue worker's log.
 */
class TestConnectionCommand extends Command
{
    protected $signature = 'automateflow:ping';

    protected $description = 'Check that the configured AutomateFlow credentials work';

    public function handle(Client $client): int
    {
        if (! $client->configured()) {
            $this->components->error('Not configured. Set AUTOMATEFLOW_URL and AUTOMATEFLOW_KEY.');

            return self::FAILURE;
        }

        try {
            $client->ping();
        } catch (AutomateFlowException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Connected to AutomateFlow.');

        return self::SUCCESS;
    }
}
