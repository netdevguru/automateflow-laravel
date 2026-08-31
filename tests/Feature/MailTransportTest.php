<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Tests\Feature;

use AutomateFlow\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

class MailTransportTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'automateflow');
        $app['config']->set('mail.mailers.automateflow', ['transport' => 'automateflow']);
        $app['config']->set('mail.from', ['address' => 'site@example.test', 'name' => 'Site']);
    }

    public function test_it_posts_the_message_to_the_transactional_endpoint(): void
    {
        Http::fake(['af.test/api/v1/transactional/send' => Http::response(['data' => ['id' => 1]])]);

        Mail::raw('Hello there', function ($message): void {
            $message->to('someone@example.test')->subject('Greetings');
        });

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://af.test/api/v1/transactional/send'
                && $body['to'] === 'someone@example.test'
                && $body['subject'] === 'Greetings'
                && str_contains($body['text'], 'Hello there')
                && $body['from_email'] === 'site@example.test';
        });
    }

    public function test_each_recipient_costs_its_own_request(): void
    {
        // The endpoint takes a single `to`, so a three-recipient message is three calls.
        // This is the package's most expensive behaviour against the rate limit and is
        // pinned so it cannot change without someone noticing.
        Http::fake(['*' => Http::response(['data' => ['id' => 1]])]);

        Mail::raw('Body', function ($message): void {
            $message->to(['a@example.test', 'b@example.test'])
                ->cc('c@example.test')
                ->subject('Fan out');
        });

        Http::assertSentCount(3);
    }

    public function test_a_failed_send_raises_a_transport_exception_so_failover_can_catch_it(): void
    {
        // Not an AutomateFlowException: Symfony's failover transport only catches
        // TransportException, so leaking the package's own type would bypass whatever
        // fallback mailer the application configured.
        Http::fake(['*' => Http::response(['message' => 'Nope.'], 500)]);

        $this->expectException(TransportException::class);

        Mail::raw('Body', fn ($message) => $message->to('x@example.test')->subject('S'));
    }

    public function test_html_mail_is_sent_as_html(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1]])]);

        Mail::html('<p>Rich</p>', fn ($message) => $message->to('x@example.test')->subject('S'));

        Http::assertSent(fn ($request) => str_contains($request->data()['html'] ?? '', '<p>Rich</p>'));
    }
}
