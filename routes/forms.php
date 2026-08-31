<?php

declare(strict_types=1);

use AutomateFlow\Laravel\Http\Controllers\FormController;
use Illuminate\Support\Facades\Route;

/*
| Relay endpoint for <x-automateflow-subscribe-form />. Registered only when
| `automateflow.features.forms` is on.
|
| In the `web` group so the session flash the component reads, and the CSRF token it
| emits, both work — this is a browser-facing form, not an API.
*/

Route::post(config('automateflow.forms.path', 'automateflow/subscribe'), FormController::class)
    ->middleware(config('automateflow.forms.middleware', ['web']))
    ->name('automateflow.form.submit');
