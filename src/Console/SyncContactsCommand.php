<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Console;

use AutomateFlow\Laravel\Concerns\SyncsToAutomateFlow;
use AutomateFlow\Laravel\Jobs\SyncContact;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Backfills existing users onto the platform.
 *
 * Queues rather than sends. Two reasons, and both bite on a real user table: the API is
 * rate limited per key, so a loop would start failing partway through with no record of
 * where; and `chunkById` over a large table inside one command is a long-running process
 * that a deploy or a timeout can kill halfway.
 *
 * Queueing makes the command's job small and finite — walk the table, dispatch — and
 * hands the pacing to the queue worker, which already knows how to retry.
 */
class SyncContactsCommand extends Command
{
    protected $signature = 'automateflow:sync-contacts {--chunk=200 : Rows to read per query}';

    protected $description = 'Queue every user for contact sync';

    public function handle(): int
    {
        $class = config('automateflow.contacts.model');

        if (! is_string($class) || ! class_exists($class)) {
            $this->components->error("Configured contacts.model [{$class}] does not exist.");

            return self::FAILURE;
        }

        /** @var Model $model */
        $model = new $class;
        $usesTrait = in_array(SyncsToAutomateFlow::class, class_uses_recursive($model), true);
        $listId = config('automateflow.contacts.list_id');

        $queued = 0;

        $model->newQuery()->chunkById((int) $this->option('chunk'), function ($users) use ($usesTrait, $listId, &$queued): void {
            foreach ($users as $user) {
                if ($usesTrait) {
                    $user->syncToAutomateFlow();
                    $queued++;

                    continue;
                }

                $email = $user->getAttribute('email');

                if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    SyncContact::dispatch($email, [], $listId);
                    $queued++;
                }
            }
        });

        $this->components->info("Queued {$queued} contact(s). Run a queue worker to drain them.");

        return self::SUCCESS;
    }
}
