<?php

namespace Tests;

use DuncanMcClean\CloudflareStaticCache\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.url', 'https://example.com');
        $app['config']->set('statamic.routes.enabled', false);
        $app['config']->set('statamic.static_caching.strategy', 'cloudflare');
        $app['config']->set('statamic.static_caching.strategies.cloudflare', [
            'driver' => 'cloudflare',
            'token' => 'test-token',
            'zones' => [
                'example.com' => 'zone-one',
                'www.example.com' => 'zone-one',
                'example.fr' => 'zone-two',
            ],
        ]);
    }
}
