<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A verified delivery from AutomateFlow.
 *
 * One event for every type rather than a class per type. The platform's catalogue is
 * open-ended and grows on its schedule, not this package's; a class per event would mean
 * an unrecognised type either being dropped or needing a release here before anyone
 * could listen for it.
 */
class WebhookReceived
{
    use Dispatchable, SerializesModels;

    /** Event types the platform is known to emit. Anything else still dispatches. */
    public const KNOWN = [
        'contact.bounced',
        'contact.complained',
        'campaign.completed',
        'automation.completed',
        'automation.failed',
        'form.submitted',
        'list.contact_added',
    ];

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $event,
        public readonly array $payload = [],
    ) {}

    public function is(string $event): bool
    {
        return $this->event === $event;
    }

    /**
     * The contact address this event concerns, wherever the payload put it.
     */
    public function email(): ?string
    {
        $candidates = [
            $this->payload['email'] ?? null,
            $this->payload['contact_email'] ?? null,
            $this->payload['contact']['email'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }
}
