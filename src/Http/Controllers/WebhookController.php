<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Http\Controllers;

use AutomateFlow\Laravel\Events\WebhookReceived;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Turns a verified webhook into a Laravel event.
 *
 * The controller does nothing else on purpose. What a given application should *do*
 * about a bounce — suspend the account, flag the row, ignore it — is its decision, and
 * baking one in would make the package wrong for everyone who wanted a different one.
 * Listen for the event instead:
 *
 *     Event::listen(WebhookReceived::class, function (WebhookReceived $event) {
 *         if ($event->is('contact.bounced')) { ... }
 *     });
 */
class WebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        // The event type travels in a header; older deliveries put it in the body.
        $event = (string) $request->header('X-Webhook-Event', '');

        if ($event === '') {
            $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';
        }

        if ($event === '') {
            return response()->json(['message' => 'Missing event type.'], 400);
        }

        WebhookReceived::dispatch($event, $payload);

        // 200 promptly. A non-2xx puts the delivery into the platform's retry schedule
        // and eventually its dead-letter path, and listeners run inline here — so heavy
        // work in a listener spends the sender's ten-second timeout. Queue it.
        return response()->json(['received' => true]);
    }
}
