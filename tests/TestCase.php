<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI\Tests;

use CPointLabs\HostYourAI\HostYourAIServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PrismServiceProvider::class, HostYourAIServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('hostyourai.api_key', 'test-key');
        $app['config']->set('hostyourai.base_url', 'https://api.test/api/v1');
    }
}
