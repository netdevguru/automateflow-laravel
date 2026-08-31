<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Http\Controllers;

use AutomateFlow\Laravel\Client;
use AutomateFlow\Laravel\Exceptions\AutomateFlowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Relays a subscription form submission, so the API key stays server-side.
 *
 * The route is CSRF-protected by the `web` group, which is why the Blade component
 * emits `@csrf`. That is worth keeping even though the endpoint is public: without it
 * any site could post subscriptions through this application's credential.
 */
class FormController
{
    public function __invoke(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'uuid' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email'],
            'fields' => ['sometimes', 'array'],
            'website' => ['nullable', 'string'],
        ]);

        // Honeypot filled means a bot. Redirect as though it worked — telling an
        // automated submitter which signal caught it just invites a retry without it.
        if (filled($validated['website'] ?? null)) {
            return $this->back(true);
        }

        $payload = ['email' => $validated['email']];

        foreach ($validated['fields'] ?? [] as $key => $value) {
            if (is_scalar($value)) {
                $payload[(string) $key] = (string) $value;
            }
        }

        try {
            $client->submitForm($validated['uuid'], $payload);
        } catch (AutomateFlowException) {
            // Deliberately generic for the visitor: the underlying message can name the
            // workspace or say why a key was rejected, neither of which is public.
            return $this->back(false);
        }

        return $this->back(true);
    }

    private function back(bool $ok): RedirectResponse
    {
        return back()->with([
            'automateflow.status' => $ok ? 'success' : 'error',
            'automateflow.message' => $ok
                ? __('Thanks — please check your inbox to confirm.')
                : __('We could not complete your subscription. Please try again shortly.'),
        ]);
    }
}
