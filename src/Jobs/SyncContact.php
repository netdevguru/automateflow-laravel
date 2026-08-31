<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Jobs;

use AutomateFlow\Laravel\Client;
use AutomateFlow\Laravel\Exceptions\AuthenticationException;
use AutomateFlow\Laravel\Exceptions\FeatureLimitException;
use AutomateFlow\Laravel\Exceptions\RequestValidationException;
use AutomateFlow\Laravel\Exceptions\ThrottledException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pushes one contact to AutomateFlow and files it on the configured list.
 *
 * ## Retry policy is the whole point of this class
 *
 * The API is rate limited per key, so a burst of registrations or a backfill will meet
 * a 429 as a matter of course. Three outcomes, deliberately different:
 *
 * - **Throttled** — `release()` back onto the queue with a delay. Not a failure, and
 *   crucially not counted as an attempt, so a busy period cannot exhaust `$tries` and
 *   discard people.
 * - **Permanently rejected** (bad address, revoked key, plan limit) — logged and
 *   swallowed. Retrying cannot change the answer, and a job that fails forever just
 *   fills the failed-jobs table with the same row.
 * - **Anything else** — thrown, so the queue's own backoff and failure handling apply.
 */
class SyncContact implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $email,
        public readonly array $fields = [],
        public readonly ?int $listId = null,
    ) {
        $config = config('automateflow.contacts', []);

        $this->onQueue($config['queue'] ?? 'default');

        if (filled($config['connection'] ?? null)) {
            $this->onConnection($config['connection']);
        }
    }

    /**
     * Exponential-ish backoff for the generic failure path.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(Client $client): void
    {
        try {
            $response = $client->upsertContact($this->email, $this->fields);
        } catch (ThrottledException $e) {
            // 60s because the tightest window the platform enforces is per-minute; a
            // shorter delay would wake up into the same closed window.
            $this->release(60);

            return;
        } catch (AuthenticationException|FeatureLimitException|RequestValidationException $e) {
            Log::warning('AutomateFlow: contact sync rejected permanently.', [
                'reason' => $e->getMessage(),
                'status' => $e->status,
            ]);

            return;
        }

        $contactId = $response['data']['id'] ?? null;

        if ($this->listId === null || ! is_int($contactId)) {
            return;
        }

        try {
            $client->addContactToList($this->listId, $contactId);
        } catch (ThrottledException) {
            // The contact itself is saved. Re-running the whole job would re-upsert it
            // pointlessly, so only the membership is retried.
            AddContactToList::dispatch($this->listId, $contactId)->delay(60);
        } catch (\Throwable $e) {
            Log::warning('AutomateFlow: contact saved but not added to the list.', [
                'list_id' => $this->listId,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
