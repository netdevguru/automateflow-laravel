<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an inbound webhook by its HMAC signature.
 *
 * ## Two details that are easy to get wrong
 *
 * **The header is the bare hex digest.** AutomateFlow sends
 * `hash_hmac('sha256', $body, $secret)` in `X-Webhook-Signature` with no `sha256=`
 * prefix, whatever the convention is elsewhere. A prefix is tolerated here in case one
 * is ever added, but stripping one that is not there would reject every delivery.
 *
 * **The signature covers the raw body, byte for byte.** `$request->getContent()` is
 * used rather than re-encoding `$request->all()`, because json_encode of a decoded
 * array reorders keys and changes escaping, and the digest then never matches.
 *
 * Comparison is hash_equals(), so the secret cannot be recovered a byte at a time by
 * timing the rejections.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('automateflow.webhooks.secret');

        // Fails closed, matching the platform's own posture on an unset SNS secret: an
        // endpoint that accepts unauthenticated events is worse than one that is off.
        if (! is_string($secret) || $secret === '') {
            Log::critical('AutomateFlow webhook secret is not configured — refusing webhook traffic.');

            return response()->json(['message' => 'Webhook secret not configured.'], 503);
        }

        $signature = (string) $request->header('X-Webhook-Signature', '');

        if ($signature === '' || ! $this->matches($request->getContent(), $signature, $secret)) {
            Log::warning('AutomateFlow webhook rejected: signature mismatch.', [
                'event' => $request->header('X-Webhook-Event', 'unknown'),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return $next($request);
    }

    private function matches(string $body, string $signature, string $secret): bool
    {
        if (str_starts_with(strtolower($signature), 'sha256=')) {
            $signature = substr($signature, 7);
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), trim($signature));
    }
}
