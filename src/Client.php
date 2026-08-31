<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel;

use AutomateFlow\Laravel\Exceptions\AuthenticationException;
use AutomateFlow\Laravel\Exceptions\AutomateFlowException;
use AutomateFlow\Laravel\Exceptions\FeatureLimitException;
use AutomateFlow\Laravel\Exceptions\RequestValidationException;
use AutomateFlow\Laravel\Exceptions\ThrottledException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * The only place in the package that talks to AutomateFlow.
 *
 * Everything funnels through request(), so the key header, the JSON envelope, the
 * timeout and — the part that earns the indirection — the error mapping are decided
 * once. A caller using Http::post() directly would get none of it and would report
 * "something went wrong" for a failure the API described precisely.
 *
 * ## Error envelopes
 *
 * The API answers failures in four recognisable shapes, and each wants a different
 * response, so each becomes its own exception rather than a status code to inspect:
 *
 * | Status | Body                                | Exception                    |
 * |--------|-------------------------------------|------------------------------|
 * | 401    | `{message}`                         | `AuthenticationException`    |
 * | 403    | `{feature_limit_exceeded, feature}` | `FeatureLimitException`      |
 * | 422    | `{message, errors:{field:[...]}}`   | `RequestValidationException` |
 * | 429    | `{rate_limit_exceeded, window}`     | `ThrottledException`         |
 *
 * `ThrottledException` is the one callers must handle rather than surface. A key is
 * limited to a fixed number of requests a minute, so any bulk operation will meet it
 * in normal use; the jobs in this package release themselves back onto the queue.
 *
 * ## Why nothing here retries on its own
 *
 * A retry loop inside the client would sit inside whatever request called it, holding
 * a web worker while it sleeps. Laravel already has a better answer for that — a
 * queued job that releases itself — so the client fails fast and truthfully, and the
 * jobs decide what is worth retrying. That division is why sync is queued rather than
 * inline: an HTTP call inside a registration request makes signup slow when the API is
 * slow and fail when it is down, for a side effect the user never asked for.
 */
class Client
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $baseUrl,
        private readonly ?string $key,
        private readonly int $timeout = 15,
    ) {}

    /**
     * Is the client configured well enough to make a call?
     */
    public function configured(): bool
    {
        return filled($this->baseUrl) && filled($this->key);
    }

    /**
     * Perform a request against /api/v1.
     *
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws AutomateFlowException
     */
    public function request(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        if (! $this->configured()) {
            throw new AutomateFlowException(
                'AutomateFlow is not configured. Set AUTOMATEFLOW_URL and AUTOMATEFLOW_KEY.'
            );
        }

        $url = rtrim((string) $this->baseUrl, '/').'/api/v1'.$path;

        try {
            $response = $this->http
                ->withHeaders([
                    'X-API-Key' => (string) $this->key,
                    'Accept' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->send($method, $url, array_filter([
                    'query' => $query ?: null,
                    'json' => $payload,
                ], static fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            // A transport failure is not the API saying no — it is the request never
            // having arrived. Callers that queue work need to tell those apart, and a
            // bare AutomateFlowException is the "unknown, probably retriable" case.
            throw new AutomateFlowException("Could not reach AutomateFlow: {$e->getMessage()}");
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        throw $this->exceptionFor($response);
    }

    /**
     * Map a failed response onto the exception that describes it.
     */
    private function exceptionFor(Response $response): AutomateFlowException
    {
        $status = $response->status();
        $body = $response->json() ?? [];

        if ($status === 429 || ! empty($body['rate_limit_exceeded'])) {
            $window = isset($body['window']) ? (string) $body['window'] : null;

            return new ThrottledException(
                $window !== null
                    ? "AutomateFlow rate limit reached ({$window})."
                    : 'AutomateFlow rate limit reached.',
                $window
            );
        }

        if (! empty($body['feature_limit_exceeded'])) {
            $feature = isset($body['feature']) ? (string) $body['feature'] : null;

            return new FeatureLimitException(
                "Your AutomateFlow plan does not include this capability ({$feature}).",
                $feature
            );
        }

        if ($status === 401) {
            return new AuthenticationException(
                (string) ($body['message'] ?? 'AutomateFlow rejected the API key.')
            );
        }

        if ($status === 422) {
            /** @var array<string, array<int, string>> $errors */
            $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];

            return new RequestValidationException($this->validationMessage($body, $errors), $errors);
        }

        return new AutomateFlowException(
            (string) ($body['message'] ?? "AutomateFlow returned an unexpected response (HTTP {$status})."),
            $status
        );
    }

    /**
     * Flatten a Laravel validation envelope into one readable sentence.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, array<int, string>>  $errors
     */
    private function validationMessage(array $body, array $errors): string
    {
        if ($errors === []) {
            return (string) ($body['message'] ?? 'AutomateFlow rejected the data as invalid.');
        }

        $parts = [];

        foreach ($errors as $field => $messages) {
            $parts[] = $field.': '.implode(' ', array_map(strval(...), (array) $messages));
        }

        return implode(' | ', $parts);
    }

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update a contact.
     *
     * The API upserts on (workspace, email), so this is safe to call repeatedly for
     * the same person — which is what lets sync be "send the current state" instead
     * of a create-or-update decision the package has to make.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function upsertContact(string $email, array $fields = []): array
    {
        return $this->request('POST', '/contacts', array_filter([
            'email' => $email,
            'first_name' => $fields['first_name'] ?? null,
            'last_name' => $fields['last_name'] ?? null,
            'custom_fields' => $fields['custom_fields'] ?? null,
        ], static fn ($value) => $value !== null));
    }

    /** @return array<string, mixed> */
    public function contacts(int $page = 1, int $perPage = 25): array
    {
        return $this->request('GET', '/contacts', null, ['page' => $page, 'per_page' => $perPage]);
    }

    /** @return array<string, mixed> */
    public function unsubscribeContact(int $contactId): array
    {
        return $this->request('POST', "/contacts/{$contactId}/unsubscribe");
    }

    /** @return array<string, mixed> */
    public function lists(): array
    {
        return $this->request('GET', '/lists', null, ['per_page' => 100]);
    }

    /** @return array<string, mixed> */
    public function addContactToList(int $listId, int $contactId): array
    {
        return $this->request('POST', "/lists/{$listId}/contacts", ['contact_id' => $contactId]);
    }

    /** @return array<string, mixed> */
    public function campaigns(int $page = 1, int $perPage = 25): array
    {
        return $this->request('GET', '/campaigns', null, ['page' => $page, 'per_page' => $perPage]);
    }

    /** @return array<string, mixed> */
    public function campaignStats(int $campaignId): array
    {
        return $this->request('GET', "/campaigns/{$campaignId}/stats");
    }

    /**
     * Start a campaign send.
     *
     * The API accepts this only for `draft` and `scheduled` campaigns and answers 422
     * otherwise, which surfaces here as RequestValidationException.
     *
     * @return array<string, mixed>
     */
    public function sendCampaign(int $campaignId, ?string $scheduledAt = null): array
    {
        return $this->request('POST', "/campaigns/{$campaignId}/send", array_filter([
            'scheduled_at' => $scheduledAt,
        ]));
    }

    /**
     * Fire an automation trigger for a contact.
     *
     * A zone-less `scheduled_at` anywhere in this API is interpreted in the
     * *workspace's* timezone, not UTC and not the caller's. Send an ISO-8601 string
     * with an offset if you mean a specific instant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function triggerAutomation(string $eventKey, string $email, array $data = []): array
    {
        return $this->request('POST', '/automations/trigger', [
            'event_key' => $eventKey,
            'contact_email' => $email,
            'data' => $data,
        ]);
    }

    /** @return array<string, mixed> */
    public function form(string $uuid): array
    {
        return $this->request('GET', '/forms/'.rawurlencode($uuid));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function submitForm(string $uuid, array $fields): array
    {
        return $this->request('POST', '/forms/'.rawurlencode($uuid).'/submit', $fields);
    }

    /**
     * Send one transactional message.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function sendTransactional(array $message): array
    {
        return $this->request('POST', '/transactional/send', $message);
    }

    /**
     * Cheapest authenticated call, for connection tests.
     *
     * Lists rather than contacts: the response is small on any workspace, where a
     * contacts page is proportional to the account.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->request('GET', '/lists', null, ['per_page' => 1]);
    }
}
