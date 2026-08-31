<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Jobs;

use AutomateFlow\Laravel\Client;
use AutomateFlow\Laravel\Exceptions\AutomateFlowException;
use AutomateFlow\Laravel\Exceptions\ThrottledException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Enrolls a contact in an automation, out of band.
 *
 * Queued for the same reason contact sync is: the caller is usually a request that has
 * something better to do — a checkout completing, an order shipping — and the trigger
 * is a side effect of it, not part of it. A failed enrollment must never fail the order.
 *
 * The caller is responsible for its own idempotency. This job will happily enroll the
 * same contact twice if dispatched twice, because it cannot know whether that is wrong:
 * "abandoned cart, again, a week later" is a legitimate second enrollment.
 */
class TriggerAutomation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $eventKey,
        public readonly string $email,
        public readonly array $data = [],
    ) {
        $this->onQueue(config('automateflow.contacts.queue', 'default'));
    }

    public function handle(Client $client): void
    {
        try {
            $client->triggerAutomation($this->eventKey, $this->email, $this->data);
        } catch (ThrottledException) {
            $this->release(60);
        } catch (AutomateFlowException $e) {
            Log::warning('AutomateFlow: automation trigger failed.', [
                'event_key' => $this->eventKey,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
