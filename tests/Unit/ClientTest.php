<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Tests\Unit;

use AutomateFlow\Laravel\Client;
use AutomateFlow\Laravel\Exceptions\AuthenticationException;
use AutomateFlow\Laravel\Exceptions\AutomateFlowException;
use AutomateFlow\Laravel\Exceptions\FeatureLimitException;
use AutomateFlow\Laravel\Exceptions\RequestValidationException;
use AutomateFlow\Laravel\Exceptions\ThrottledException;
use AutomateFlow\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * The error mapping is the client's real job, so that is what these cover:
 * each envelope the API can answer with becomes the exception a caller catches.
 */
class ClientTest extends TestCase
{
    private function client(): Client
    {
        return $this->app->make(Client::class);
    }

    public function test_it_sends_the_api_key_and_returns_decoded_json(): void
    {
        Http::fake(['af.test/api/v1/lists*' => Http::response(['data' => [['id' => 1, 'name' => 'Newsletter']]])]);

        $result = $this->client()->lists();

        $this->assertSame('Newsletter', $result['data'][0]['name']);

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'test-key'));
    }

    public function test_a_429_becomes_a_throttled_exception_carrying_the_window(): void
    {
        Http::fake(['*' => Http::response(['rate_limit_exceeded' => true, 'window' => 'per_minute'], 429)]);

        try {
            $this->client()->lists();
            $this->fail('Expected ThrottledException.');
        } catch (ThrottledException $e) {
            $this->assertSame('per_minute', $e->window);
            $this->assertSame(429, $e->status);
        }
    }

    public function test_a_403_feature_envelope_becomes_a_feature_limit_exception(): void
    {
        Http::fake(['*' => Http::response(['feature_limit_exceeded' => true, 'feature' => 'api_access_enabled'], 403)]);

        try {
            $this->client()->lists();
            $this->fail('Expected FeatureLimitException.');
        } catch (FeatureLimitException $e) {
            $this->assertSame('api_access_enabled', $e->feature);
        }
    }

    public function test_a_401_becomes_an_authentication_exception(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid API key.'], 401)]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key.');

        $this->client()->lists();
    }

    public function test_a_422_flattens_field_errors_into_the_message(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['email' => ['The email field is required.']],
        ], 422)]);

        try {
            $this->client()->upsertContact('nope');
            $this->fail('Expected RequestValidationException.');
        } catch (RequestValidationException $e) {
            $this->assertStringContainsString('email: The email field is required.', $e->getMessage());
            $this->assertArrayHasKey('email', $e->errors);
        }
    }

    public function test_it_refuses_to_call_when_unconfigured(): void
    {
        config()->set('automateflow.key', null);

        $this->expectException(AutomateFlowException::class);
        $this->expectExceptionMessage('not configured');

        $this->app->forgetInstance(Client::class);
        $this->app->make(Client::class)->lists();
    }

    public function test_upsert_contact_omits_absent_optional_fields(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 7]], 201)]);

        $this->client()->upsertContact('a@b.test', ['first_name' => 'Ada']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['email'] === 'a@b.test'
                && $body['first_name'] === 'Ada'
                && ! array_key_exists('last_name', $body)
                && ! array_key_exists('custom_fields', $body);
        });
    }
}
