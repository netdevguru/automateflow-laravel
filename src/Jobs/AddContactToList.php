<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Jobs;

use AutomateFlow\Laravel\Client;
use AutomateFlow\Laravel\Exceptions\ThrottledException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Retries just the list membership after a contact was already saved.
 *
 * Split out of SyncContact so a throttled membership call does not re-upsert a contact
 * that is already correct — the upsert is idempotent, but it still costs a request from
 * the budget that was exhausted in the first place.
 */
class AddContactToList implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $listId,
        public readonly int $contactId,
    ) {
        $this->onQueue(config('automateflow.contacts.queue', 'default'));
    }

    public function handle(Client $client): void
    {
        try {
            $client->addContactToList($this->listId, $this->contactId);
        } catch (ThrottledException) {
            $this->release(60);
        }
    }
}
