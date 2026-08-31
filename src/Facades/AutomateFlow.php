<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Facades;

use AutomateFlow\Laravel\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool configured()
 * @method static array<string, mixed> request(string $method, string $path, ?array $payload = null, array $query = [])
 * @method static array<string, mixed> upsertContact(string $email, array $fields = [])
 * @method static array<string, mixed> contacts(int $page = 1, int $perPage = 25)
 * @method static array<string, mixed> unsubscribeContact(int $contactId)
 * @method static array<string, mixed> lists()
 * @method static array<string, mixed> addContactToList(int $listId, int $contactId)
 * @method static array<string, mixed> campaigns(int $page = 1, int $perPage = 25)
 * @method static array<string, mixed> campaignStats(int $campaignId)
 * @method static array<string, mixed> sendCampaign(int $campaignId, ?string $scheduledAt = null)
 * @method static array<string, mixed> triggerAutomation(string $eventKey, string $email, array $data = [])
 * @method static array<string, mixed> form(string $uuid)
 * @method static array<string, mixed> submitForm(string $uuid, array $fields)
 * @method static array<string, mixed> sendTransactional(array $message)
 * @method static array<string, mixed> ping()
 *
 * @see Client
 */
class AutomateFlow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'automateflow';
    }
}
