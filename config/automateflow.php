<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | `base_url` is the root of the AutomateFlow install; requests go to /api/v1
    | beneath it. `key` is a workspace API key, shown once when it is created.
    |
    | A key carries workspace scope and no role, and the scope it needs is derived
    | from the HTTP method — safe methods need `read`, everything else `write` — so
    | one key with both covers everything this package does.
    |
    */

    'base_url' => env('AUTOMATEFLOW_URL'),

    'key' => env('AUTOMATEFLOW_KEY'),

    'timeout' => (int) env('AUTOMATEFLOW_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Feature switches
    |--------------------------------------------------------------------------
    |
    | Every one defaults to off. Installing a package must not start shipping your
    | user table to a third party or rewriting how your application sends mail;
    | both are decisions, and a decision has to be made rather than inherited.
    |
    */

    'features' => [
        'contacts' => (bool) env('AUTOMATEFLOW_SYNC_CONTACTS', false),
        'forms' => (bool) env('AUTOMATEFLOW_FORMS', false),
        'webhooks' => (bool) env('AUTOMATEFLOW_WEBHOOKS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription forms
    |--------------------------------------------------------------------------
    |
    | <x-automateflow-subscribe-form uuid="..." /> posts to this route, which relays
    | the submission with the API key attached. The key is workspace-scoped and can
    | write, so it must never reach the browser — that indirection is the whole point.
    |
    | The route lives in the `web` group because it is a browser form: it needs the
    | session for its status message and CSRF for the reason any public form does.
    |
    */

    'forms' => [
        'path' => env('AUTOMATEFLOW_FORM_PATH', 'automateflow/subscribe'),

        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contact sync
    |--------------------------------------------------------------------------
    |
    | `model` is your user class. `list_id` is the list new contacts join, or null
    | for none. `attributes` maps AutomateFlow custom fields to either a model
    | attribute name or a callable receiving the model.
    |
    | `queue` and `connection` place the sync jobs; leaving connection null uses the
    | application default. Sync is always queued — see the Client docblock for why
    | an inline HTTP call in a registration request is the wrong shape.
    |
    */

    'contacts' => [
        'model' => env('AUTOMATEFLOW_USER_MODEL', 'App\\Models\\User'),

        'list_id' => env('AUTOMATEFLOW_LIST_ID') ? (int) env('AUTOMATEFLOW_LIST_ID') : null,

        'attributes' => [
            // 'plan' => 'subscription_plan',
            // 'signed_up_at' => fn ($user) => $user->created_at?->toDateString(),
        ],

        'queue' => env('AUTOMATEFLOW_QUEUE', 'default'),

        'connection' => env('AUTOMATEFLOW_QUEUE_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | Registers an `automateflow` mail transport. Point a mailer at it in
    | config/mail.php:
    |
    |     'automateflow' => ['transport' => 'automateflow'],
    |
    | The transactional endpoint accepts one recipient per call, so a message
    | addressed to N people costs N requests against the per-key rate limit.
    |
    | For a fallback, use Laravel's own failover transport rather than anything in
    | this package — it already solves exactly this, and a second implementation
    | would be a worse one:
    |
    |     'default' => ['transport' => 'failover', 'mailers' => ['automateflow', 'smtp']]
    |
    */

    'mail' => [
        'from_email' => env('AUTOMATEFLOW_FROM_EMAIL'),
        'from_name' => env('AUTOMATEFLOW_FROM_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | `secret` is the shared secret from the endpoint you registered in AutomateFlow.
    | With it unset the endpoint refuses every request rather than trusting them —
    | the same fail-closed posture the platform's own SNS webhook takes.
    |
    | The signature is the bare hex digest of hash_hmac('sha256', $rawBody, $secret),
    | with no `sha256=` prefix, and it covers the body byte for byte.
    |
    */

    'webhooks' => [
        'secret' => env('AUTOMATEFLOW_WEBHOOK_SECRET'),

        'path' => env('AUTOMATEFLOW_WEBHOOK_PATH', 'automateflow/webhook'),

        'middleware' => ['api'],
    ],

];
