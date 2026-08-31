<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel;

use AutomateFlow\Laravel\Console\CampaignsCommand;
use AutomateFlow\Laravel\Console\SyncContactsCommand;
use AutomateFlow\Laravel\Console\TestConnectionCommand;
use AutomateFlow\Laravel\Listeners\SyncRegisteredUser;
use AutomateFlow\Laravel\Mail\AutomateFlowTransport;
use AutomateFlow\Laravel\View\Components\SubscribeForm;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the package into the host application.
 *
 * Registration order matters in one place and is otherwise unremarkable: the mail
 * transport must be extended in boot(), after the mail manager exists, while the
 * Client binding belongs in register() because a transport resolved during boot will
 * ask for it.
 *
 * Every feature is switched on by config and defaults to off. A package that starts
 * synchronising your user table because it was installed has made a decision that was
 * not its to make.
 */
class AutomateFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/automateflow.php', 'automateflow');

        $this->app->singleton(Client::class, function ($app): Client {
            $config = $app['config']->get('automateflow');

            return new Client(
                $app->make(HttpFactory::class),
                $config['base_url'] ?? null,
                $config['key'] ?? null,
                (int) ($config['timeout'] ?? 15),
            );
        });

        $this->app->alias(Client::class, 'automateflow');
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMailTransport();
        $this->registerRoutes();
        $this->registerListeners();
        $this->registerViews();

        if ($this->app->runningInConsole()) {
            $this->commands([
                TestConnectionCommand::class,
                SyncContactsCommand::class,
                CampaignsCommand::class,
            ]);
        }
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/automateflow.php' => $this->app->configPath('automateflow.php'),
        ], 'automateflow-config');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/automateflow'),
        ], 'automateflow-views');
    }

    /**
     * Add an `automateflow` transport to the mail manager.
     *
     * Registered unconditionally rather than behind a feature flag: an unused transport
     * costs nothing, and the switch that matters is which mailer config/mail.php points
     * at. Gating it here would mean a mailer referring to a transport that silently does
     * not exist, which fails at send time with a confusing message.
     */
    private function registerMailTransport(): void
    {
        Mail::extend('automateflow', function (array $config): AutomateFlowTransport {
            $mail = $this->app['config']->get('automateflow.mail', []);

            return new AutomateFlowTransport(
                $this->app->make(Client::class),
                $config['from_email'] ?? $mail['from_email'] ?? null,
                $config['from_name'] ?? $mail['from_name'] ?? null,
            );
        });
    }

    /**
     * Expose the webhook endpoint.
     *
     * Off by default, and off means the route does not exist — not that it exists and
     * rejects. An endpoint that is present but inert is still a surface to probe.
     */
    private function registerRoutes(): void
    {
        $config = $this->app['config'];

        if ($config->get('automateflow.features.webhooks', false)) {
            Route::group([
                'middleware' => $config->get('automateflow.webhooks.middleware', ['api']),
            ], function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
            });
        }

        if ($config->get('automateflow.features.forms', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/forms.php');
        }
    }

    /**
     * Sync users as they register.
     *
     * Hooked to Laravel's own `Registered` event rather than to a model observer,
     * because a `created` observer also fires for factory-made users in tests and for
     * seeders, and neither of those wants a network call. Applications that create
     * users outside the auth scaffolding can dispatch the job directly or use the
     * SyncsToAutomateFlow trait.
     */
    private function registerListeners(): void
    {
        if (! $this->app['config']->get('automateflow.features.contacts', false)) {
            return;
        }

        Event::listen(Registered::class, SyncRegisteredUser::class);
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'automateflow');

        Blade::component('automateflow-subscribe-form', SubscribeForm::class);
    }
}
