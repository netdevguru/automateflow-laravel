<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Tests\Feature;

use AutomateFlow\Laravel\Events\WebhookReceived;
use AutomateFlow\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * The signature check is the endpoint's only authentication, so these tests are the
 * ones that matter most in the package.
 */
class WebhookTest extends TestCase
{
    private const SECRET = 'shhh';

    private function postWebhook(string $body, ?string $signature, string $event = 'campaign.completed'): TestResponse
    {
        return $this->call(
            'POST',
            '/automateflow/webhook',
            [],
            [],
            [],
            array_filter([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
                'HTTP_X_WEBHOOK_EVENT' => $event,
            ]),
            $body
        );
    }

    public function test_a_correctly_signed_delivery_dispatches_the_event(): void
    {
        Event::fake([WebhookReceived::class]);

        $body = json_encode(['campaign_id' => 12]);

        $this->postWebhook($body, hash_hmac('sha256', $body, self::SECRET))
            ->assertOk()
            ->assertJson(['received' => true]);

        Event::assertDispatched(
            WebhookReceived::class,
            fn (WebhookReceived $e) => $e->is('campaign.completed') && $e->payload['campaign_id'] === 12
        );
    }

    public function test_the_bare_hex_digest_is_what_is_expected_not_a_prefixed_one(): void
    {
        // The platform sends hash_hmac() output with no `sha256=` prefix. A receiver
        // written to strip one would reject every real delivery — this pins that.
        Event::fake([WebhookReceived::class]);

        $body = json_encode(['ok' => true]);
        $digest = hash_hmac('sha256', $body, self::SECRET);

        $this->postWebhook($body, $digest)->assertOk();

        // A prefixed form is tolerated too, in case one is ever added.
        $this->postWebhook($body, 'sha256='.$digest)->assertOk();
    }

    public function test_a_wrong_signature_is_rejected(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->postWebhook(json_encode(['a' => 1]), hash_hmac('sha256', 'different body', self::SECRET))
            ->assertStatus(401);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        $this->postWebhook(json_encode(['a' => 1]), null)->assertStatus(401);
    }

    public function test_it_fails_closed_when_no_secret_is_configured(): void
    {
        config()->set('automateflow.webhooks.secret', null);

        $body = json_encode(['a' => 1]);

        $this->postWebhook($body, hash_hmac('sha256', $body, self::SECRET))->assertStatus(503);
    }

    public function test_a_signed_delivery_without_an_event_type_is_a_400(): void
    {
        $body = json_encode(['a' => 1]);

        $this->postWebhook($body, hash_hmac('sha256', $body, self::SECRET), '')->assertStatus(400);
    }

    public function test_the_event_finds_the_contact_email_wherever_the_payload_puts_it(): void
    {
        $nested = new WebhookReceived('contact.bounced', ['contact' => ['email' => 'x@y.test']]);
        $flat = new WebhookReceived('contact.bounced', ['contact_email' => 'a@b.test']);
        $none = new WebhookReceived('campaign.completed', ['campaign_id' => 1]);

        $this->assertSame('x@y.test', $nested->email());
        $this->assertSame('a@b.test', $flat->email());
        $this->assertNull($none->email());
    }
}
