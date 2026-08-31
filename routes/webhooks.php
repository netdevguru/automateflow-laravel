<?php

declare(strict_types=1);

use AutomateFlow\Laravel\Http\Controllers\WebhookController;
use AutomateFlow\Laravel\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

/*
| The endpoint AutomateFlow posts delivery events to. Registered only when
| `automateflow.features.webhooks` is on — see AutomateFlowServiceProvider.
|
| The signature middleware is attached here rather than in the group config so it
| cannot be dropped by an application overriding `automateflow.webhooks.middleware`,
| which is meant for adding throttling, not for removing authentication.
*/

Route::post(config('automateflow.webhooks.path', 'automateflow/webhook'), WebhookController::class)
    ->middleware(VerifyWebhookSignature::class)
    ->name('automateflow.webhook');
