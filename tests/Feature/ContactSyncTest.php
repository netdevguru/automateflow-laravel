<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Tests\Feature;

use AutomateFlow\Laravel\Client;
use AutomateFlow\Laravel\Jobs\SyncContact;
use AutomateFlow\Laravel\Tests\Fixtures\SyncableUser;
use AutomateFlow\Laravel\Tests\TestCase;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

class ContactSyncTest extends TestCase
{
    public function test_the_job_upserts_and_files_the_contact_on_the_list(): void
    {
        Http::fake([
            'af.test/api/v1/contacts' => Http::response(['data' => ['id' => 42]], 201),
            'af.test/api/v1/lists/*/contacts' => Http::response(['data' => []], 201),
        ]);

        (new SyncContact('a@b.test', ['first_name' => 'Ada'], 9))->handle($this->app->make(Client::class));

        Http::assertSent(fn ($r) => $r->url() === 'https://af.test/api/v1/contacts');
        Http::assertSent(fn ($r) => $r->url() === 'https://af.test/api/v1/lists/9/contacts' && $r->data()['contact_id'] === 42);
    }

    public function test_a_throttled_upsert_releases_the_job_rather_than_failing_it(): void
    {
        // The important property: throttling must cost latency, never a record. A job
        // that failed here would burn an attempt and eventually be discarded.
        Http::fake(['*' => Http::response(['rate_limit_exceeded' => true, 'window' => 'per_minute'], 429)]);

        $job = new SyncContact('a@b.test');
        $job->job = \Mockery::mock(Job::class);
        $job->job->shouldReceive('release')->once()->with(60);
        $job->job->shouldReceive('isReleased')->andReturn(true);
        $job->job->shouldReceive('isDeletedOrReleased')->andReturn(true);
        $job->job->shouldReceive('hasFailed')->andReturn(false);

        $job->handle($this->app->make(Client::class));
    }

    public function test_a_permanent_rejection_is_swallowed_not_retried_forever(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid API key.'], 401)]);

        $job = new SyncContact('a@b.test');

        // No exception escapes: retrying a revoked key cannot change the answer, and a
        // job that fails forever just fills the failed-jobs table with one row repeated.
        $job->handle($this->app->make(Client::class));

        $this->assertTrue(true);
    }

    public function test_registering_a_user_queues_a_sync(): void
    {
        Bus::fake();

        event(new Registered(
            new SyncableUser(['email' => 'new@example.test', 'name' => 'Ada Lovelace'])
        ));

        Bus::assertDispatched(SyncContact::class, fn (SyncContact $job) => $job->email === 'new@example.test');
    }

    public function test_the_trait_splits_a_single_name_column_into_first_and_last(): void
    {
        $user = new SyncableUser(['email' => 'a@b.test', 'name' => 'Ada Lovelace']);

        $fields = $user->automateFlowFields();

        $this->assertSame('Ada', $fields['first_name']);
        $this->assertSame('Lovelace', $fields['last_name']);
    }

    public function test_configured_attributes_become_custom_fields(): void
    {
        config()->set('automateflow.contacts.attributes', [
            'plan' => 'plan',
            'shouted' => fn ($user) => strtoupper((string) $user->plan),
        ]);

        $user = new SyncableUser(['email' => 'a@b.test', 'plan' => 'pro']);

        $this->assertSame(['plan' => 'pro', 'shouted' => 'PRO'], $user->automateFlowCustomFields());
    }
}
