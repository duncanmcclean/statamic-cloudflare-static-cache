<?php

namespace Tests;

use DuncanMcClean\CloudflareStaticCache\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\StaticCacheManager;

class DefaultStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_URL=https://example.com');
        putenv('CLOUDFLARE_API_TOKEN=env-token');
        putenv('CLOUDFLARE_ZONE_ID=env-zone');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('APP_URL');
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('CLOUDFLARE_ZONE_ID');
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('statamic.static_caching.strategy', 'cloudflare');
    }

    #[Test]
    public function it_configures_the_app_url_host_from_env_variables()
    {
        $cacher = app(Cacher::class);

        $this->assertEquals('env-token', $cacher->config('token'));
        $this->assertEquals(['example.com' => 'env-zone'], $cacher->zones());
    }

    #[Test]
    public function it_only_replaces_the_options_set_in_the_config()
    {
        config()->set('statamic.static_caching.strategies.cloudflare', ['zones' => ['example.fr' => 'fr-zone']]);

        (new ServiceProvider($this->app))->register();
        $this->app->forgetInstance(StaticCacheManager::class);

        $cacher = app(Cacher::class);

        $this->assertEquals('env-token', $cacher->config('token'));
        $this->assertEquals(['example.fr' => 'fr-zone'], $cacher->zones());
    }
}
