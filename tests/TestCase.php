<?php

declare(strict_types=1);

namespace AutomateFlow\Laravel\Tests;

use AutomateFlow\Laravel\AutomateFlowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AutomateFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('automateflow.base_url', 'https://af.test');
        $app['config']->set('automateflow.key', 'test-key');
        $app['config']->set('automateflow.features.contacts', true);
        $app['config']->set('automateflow.features.webhooks', true);
        $app['config']->set('automateflow.features.forms', true);
        $app['config']->set('automateflow.webhooks.secret', 'shhh');
    }
}
