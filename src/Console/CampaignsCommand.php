<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Console;

use AutomateFlow\Laravel\Client;
use AutomateFlow\Laravel\Exceptions\AutomateFlowException;
use Illuminate\Console\Command;

/**
 * Lists campaigns, and optionally shows one campaign's numbers.
 *
 * Stats are fetched only for a named campaign, never per row: the list endpoint does not
 * carry them, so a stats column would cost one request per row against a key limited to
 * roughly a request a second.
 */
class CampaignsCommand extends Command
{
    protected $signature = 'automateflow:campaigns {--page=1} {--stats= : Show statistics for this campaign id}';

    protected $description = 'List AutomateFlow campaigns';

    public function handle(Client $client): int
    {
        try {
            if ($id = $this->option('stats')) {
                return $this->showStats($client, (int) $id);
            }

            $response = $client->campaigns((int) $this->option('page'));
        } catch (AutomateFlowException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = collect($response['data'] ?? [])->map(fn (array $c): array => [
            $c['id'] ?? '',
            $c['name'] ?? '',
            $c['status'] ?? '',
            $c['sends_count'] ?? 0,
        ])->all();

        if ($rows === []) {
            $this->components->info('No campaigns found.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Status', 'Recipients'], $rows);
        $this->line(sprintf('Page %s of %s', $response['current_page'] ?? 1, $response['last_page'] ?? 1));

        return self::SUCCESS;
    }

    private function showStats(Client $client, int $id): int
    {
        $stats = $client->campaignStats($id)['data'] ?? [];

        $this->table(
            ['Metric', 'Count'],
            collect($stats)->except('status')->map(fn ($v, $k): array => [$k, $v])->values()->all()
        );

        // Worth saying every time it is displayed rather than once in the docs: opens
        // count loaded tracking pixels, some of which are machines.
        $this->components->warn('Open counts include some machine fetches — treat them as directional.');

        return self::SUCCESS;
    }
}
