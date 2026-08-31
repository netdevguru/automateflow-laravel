# AutomateFlow for Laravel

Connect a Laravel application to an AutomateFlow workspace. The Laravel counterpart of
`wordpress/automateflow`, and like it, a client of the public `/api/v1` contract with no
privileged access — the same surface any third party gets.

```bash
composer require automateflow/laravel
php artisan vendor:publish --tag=automateflow-config
```

```dotenv
AUTOMATEFLOW_URL=https://app.example.com
AUTOMATEFLOW_KEY=your-workspace-api-key
```

```bash
php artisan automateflow:ping
```

Every feature is off until switched on. Installing a package should not start shipping your
user table to a third party or rewriting how your application sends mail.

## What it does

| Feature | Switch | Entry point |
|---|---|---|
| Contact sync | `AUTOMATEFLOW_SYNC_CONTACTS` | `SyncsToAutomateFlow` trait, `Registered` listener, `automateflow:sync-contacts` |
| Transactional mail | config/mail.php | `automateflow` mail transport |
| Subscription forms | `AUTOMATEFLOW_FORMS` | `<x-automateflow-subscribe-form uuid="…" />` |
| Automation triggers | — | `AutomateFlow::triggerAutomation()`, `TriggerAutomation` job |
| Campaigns | — | `automateflow:campaigns` |
| Incoming webhooks | `AUTOMATEFLOW_WEBHOOKS` | `WebhookReceived` event |

## Contact sync

Add the trait to your user model and sync where it makes sense:

```php
use AutomateFlow\Laravel\Concerns\SyncsToAutomateFlow;

class User extends Authenticatable
{
    use SyncsToAutomateFlow;
}

$user->syncToAutomateFlow();
$user->triggerAutomateFlow('trial_expiring', ['days_left' => 3]);
```

**Nothing hooks model events, deliberately.** A `saved` observer fires for every factory in
your test suite, every seeder row and every unrelated column update — a queued network call
each time, and in tests against an API that is not there. The package wires up only the one
moment it can be sure of, Laravel's `Registered` event; everything else is your call.

Map extra fields in `config/automateflow.php`. Values may be an attribute name or a callable,
so a computed field needs no column:

```php
'attributes' => [
    'plan' => 'subscription_plan',
    'lifetime_value' => fn ($user) => $user->orders()->sum('total'),
],
```

Backfill existing users with `php artisan automateflow:sync-contacts`. It queues rather than
sends — see *Rate limiting* below.

## Mail

Point a mailer at the transport:

```php
'mailers' => [
    'automateflow' => ['transport' => 'automateflow'],
],
```

**For a fallback, use Laravel's own failover transport, not something in this package:**

```php
'default' => env('MAIL_MAILER', 'failover'),

'mailers' => [
    'failover' => [
        'transport' => 'failover',
        'mailers' => ['automateflow', 'smtp'],
    ],
],
```

The WordPress plugin hand-rolls a fallback because WordPress has no such primitive. Laravel
does, and it is better than a reimplementation would be: it remembers which transport failed
and prefers the next for a cooldown, rather than paying the timeout on every message. The
transport throws `TransportException` precisely so failover can catch it.

## Webhooks

Register `https://your-app.test/automateflow/webhook` as an endpoint in AutomateFlow, put the
generated secret in `AUTOMATEFLOW_WEBHOOK_SECRET`, then listen:

```php
Event::listen(WebhookReceived::class, function (WebhookReceived $event) {
    if ($event->is('contact.bounced') && $email = $event->email()) {
        // your policy here
    }
});
```

The controller dispatches an event and does nothing else. What a bounce *means* for an
application — suspend the account, flag the row, ignore it — is a decision this package should
not make for you.

With no secret configured the endpoint returns 503 rather than trusting the caller, matching
the platform's own posture on an unset SNS secret.

## Contract details that are easy to get wrong

- **The webhook signature is the bare hex digest.** `hash_hmac('sha256', $body, $secret)` in
  `X-Webhook-Signature`, with **no `sha256=` prefix**. A prefix is tolerated in case one is
  added later, but a receiver that strips one unconditionally rejects every real delivery.
  Pinned by a test.
- **The signature covers the raw body.** Verified against `$request->getContent()`;
  re-encoding the decoded array reorders keys and breaks every signature.
- **`POST /contacts` upserts** on `(workspace, email)`, so sync is "send current state".
- **`/transactional/send` takes one recipient.** A message to N people is N requests. Cc and
  Bcc are expanded into ordinary recipients — that loses the appearance of a copy, not the
  copy.
- **`POST /campaigns/{id}/send` accepts only `draft` and `scheduled`** and 422s otherwise.
- **A zone-less `scheduled_at` means the workspace's timezone**, not UTC and not yours. Send
  an ISO-8601 string with an offset if you mean a specific instant.

## Rate limiting shapes the design

A key is limited per minute (60 by default). Everything bulk is therefore a queued job that
releases itself on a 429 rather than a loop that fails partway through with no record of
where. Throttling costs latency; it must never cost a record.

`ThrottledException` is the one exception callers should handle rather than surface.

## Testing

```bash
composer install
composer test     # 27 tests
composer format
```

The suite runs on Orchestra Testbench with `Http::fake()` — no network, no AutomateFlow
install required.

## License

MIT.
