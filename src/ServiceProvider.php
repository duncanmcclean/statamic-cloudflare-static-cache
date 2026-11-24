<?php

namespace DuncanMcClean\CloudflareStaticCache;

use Statamic\Providers\AddonServiceProvider;
use Statamic\StaticCaching\Cachers\Writer;
use Statamic\StaticCaching\StaticCacheManager;

class ServiceProvider extends AddonServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/strategy.php', 'statamic.static_caching.strategies.cloudflare');

        $this->app->extend(StaticCacheManager::class, function (StaticCacheManager $manager) {
            return $manager->extend('cloudflare', function ($app, $config) {
                return new CloudflareCacher(new Writer, $this->cacheStore(), $config);
            });
        });
    }
}
