<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Listeners;

use AutomateFlow\Laravel\Concerns\SyncsToAutomateFlow;
use AutomateFlow\Laravel\Jobs\SyncContact;
use Illuminate\Auth\Events\Registered;

/**
 * Syncs a user the moment Laravel says they registered.
 *
 * Works with or without the SyncsToAutomateFlow trait: if the model has it, the model's
 * own field mapping is used, so an application that has customised the payload gets that
 * customisation here too. Otherwise a minimal payload is built from the email.
 */
class SyncRegisteredUser
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (in_array(SyncsToAutomateFlow::class, class_uses_recursive($user), true)) {
            $user->syncToAutomateFlow();

            return;
        }

        $email = $user->getAttribute('email');

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        SyncContact::dispatch($email, [], config('automateflow.contacts.list_id'));
    }
}
