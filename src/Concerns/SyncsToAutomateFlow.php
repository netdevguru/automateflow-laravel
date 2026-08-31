<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Concerns;

use AutomateFlow\Laravel\Jobs\SyncContact;
use AutomateFlow\Laravel\Jobs\TriggerAutomation;

/**
 * Opt a model into contact sync.
 *
 * Add to your user model and the contact is kept current whenever you ask for it:
 *
 *     class User extends Authenticatable
 *     {
 *         use SyncsToAutomateFlow;
 *     }
 *
 *     $user->syncToAutomateFlow();
 *     $user->triggerAutomateFlow('trial_expiring', ['days_left' => 3]);
 *
 * Nothing here hooks model events. That is deliberate: a `saved` observer would fire
 * for every factory in your test suite, every seeder row, and every unrelated column
 * update — a queued network call each time, and in tests against an API that is not
 * there. Sync is something you ask for at the point you know it is warranted; the
 * package wires up only the one moment it can be sure of, Laravel's `Registered` event.
 */
trait SyncsToAutomateFlow
{
    /**
     * Queue this model's current state to AutomateFlow.
     */
    public function syncToAutomateFlow(): void
    {
        $email = $this->automateFlowEmail();

        if ($email === null) {
            return;
        }

        SyncContact::dispatch(
            $email,
            $this->automateFlowFields(),
            config('automateflow.contacts.list_id')
        );
    }

    /**
     * Enroll this model's contact in an automation.
     *
     * @param  array<string, mixed>  $data
     */
    public function triggerAutomateFlow(string $eventKey, array $data = []): void
    {
        $email = $this->automateFlowEmail();

        if ($email === null) {
            return;
        }

        TriggerAutomation::dispatch($eventKey, $email, $data);
    }

    /**
     * The address to sync under. Override for a model whose email is not `email`.
     */
    public function automateFlowEmail(): ?string
    {
        $email = $this->getAttribute('email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Build the contact payload.
     *
     * Names are split from a single `name` column when first/last are absent, because
     * that is what Laravel's own scaffolding gives you and requiring the two columns
     * would mean every default install has to override this.
     *
     * @return array<string, mixed>
     */
    public function automateFlowFields(): array
    {
        $first = $this->getAttribute('first_name');
        $last = $this->getAttribute('last_name');

        if (! is_string($first) && is_string($name = $this->getAttribute('name'))) {
            $parts = preg_split('/\s+/', trim($name), 2) ?: [];
            $first = $parts[0] ?? null;
            $last = $parts[1] ?? null;
        }

        return array_filter([
            'first_name' => $first,
            'last_name' => $last,
            'custom_fields' => $this->automateFlowCustomFields(),
        ], static fn ($value) => $value !== null && $value !== []);
    }

    /**
     * Resolve the configured attribute map into custom fields.
     *
     * Values may be an attribute name or a callable taking the model, so a computed
     * field ("lifetime value", "plan") does not need a database column to be syncable.
     *
     * @return array<string, mixed>
     */
    public function automateFlowCustomFields(): array
    {
        $out = [];

        foreach ((array) config('automateflow.contacts.attributes', []) as $field => $source) {
            $value = is_callable($source) ? $source($this) : $this->getAttribute((string) $source);

            if (is_scalar($value) && $value !== '') {
                $out[(string) $field] = $value;
            }
        }

        return $out;
    }
}
