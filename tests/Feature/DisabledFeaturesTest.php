<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Tests\Feature;

use AutomateFlow\Laravel\Tests\TestCase;

/**
 * With a feature off, its route must not exist at all.
 *
 * "Off" meaning "registered but refusing" would still leave a surface to probe and would
 * still consume a route entry; absent is the only honest reading of disabled.
 */
class DisabledFeaturesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('automateflow.features.webhooks', false);
        $app['config']->set('automateflow.features.forms', false);
    }

    public function test_no_webhook_route_is_registered(): void
    {
        $this->assertFalse($this->hasRoute('automateflow/webhook'));
    }

    public function test_no_form_route_is_registered(): void
    {
        $this->assertFalse($this->hasRoute('automateflow/subscribe'));
    }

    public function test_posting_to_the_webhook_path_is_a_404(): void
    {
        $this->postJson('/automateflow/webhook', [])->assertNotFound();
    }

    private function hasRoute(string $uri): bool
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->uri() === $uri) {
                return true;
            }
        }

        return false;
    }
}
