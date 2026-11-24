<?php

namespace Tests;

use DuncanMcClean\CloudflareStaticCache\Jobs\PurgeCloudflareCache;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Console\Commands\StaticWarmJob;
use Statamic\Facades\StaticCache;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Middleware\Cache as StaticCacheMiddleware;
use Statamic\StaticCaching\StaticCacheManager;

class CloudflareCacherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Route::get('/about', fn () => 'About us')->middleware(['web', StaticCacheMiddleware::class]);
    }

    #[Test]
    public function it_makes_pages_cacheable_by_cloudflare()
    {
        $response = $this->get('https://example.com/about');

        $response->assertOk();

        $this->assertEquals('max-age=0, must-revalidate, public', $response->headers->get('Cache-Control'));
        $this->assertEquals('max-age=31536000', $response->headers->get('CDN-Cache-Control'));
        $this->assertEmpty($response->headers->getCookies());
        $this->assertContains('/about', app(Cacher::class)->getUrls('https://example.com')->all());
    }

    #[Test]
    public function it_uses_the_expiry_as_the_edge_ttl()
    {
        config()->set('statamic.static_caching.strategies.cloudflare.expiry', 30);
        app()->forgetInstance(StaticCacheManager::class);

        $this->get('https://example.com/about')->assertHeader('CDN-Cache-Control', 'max-age=1800');
    }

    #[Test]
    public function it_does_not_make_pages_cacheable_for_logged_in_users()
    {
        $this->actingAs(new User);

        $response = $this->get('https://example.com/about');

        $this->assertFalse($response->headers->has('CDN-Cache-Control'));
        $this->assertEmpty(app(Cacher::class)->getUrls('https://example.com'));
    }

    #[Test]
    public function it_does_not_make_excluded_pages_cacheable()
    {
        config()->set('statamic.static_caching.exclude', ['urls' => ['/about']]);
        app()->forgetInstance(StaticCacheManager::class);

        $response = $this->get('https://example.com/about');

        $this->assertFalse($response->headers->has('CDN-Cache-Control'));
    }

    #[Test]
    public function it_never_serves_pages_itself()
    {
        $this->get('https://example.com/about');

        $this->assertFalse(app(Cacher::class)->hasCachedPage(Request::create('https://example.com/about')));
    }

    #[Test]
    public function it_purges_and_warms_invalidated_urls_including_their_query_string_variants()
    {
        Http::fake();
        Queue::fake([StaticWarmJob::class]);

        $cacher = app(Cacher::class);
        $cacher->cacheUrl('a', '/about', 'https://example.com');
        $cacher->cacheUrl('b', '/about?page=2', 'https://example.com');
        $cacher->cacheUrl('c', '/contact', 'https://example.com');

        $cacher->invalidateUrls(['/about']);

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-one/purge_cache'
            && $request['files'] === ['https://example.com/about', 'https://example.com/about?page=2']);

        $this->assertEquals(['c' => '/contact'], $cacher->getUrls('https://example.com')->all());

        Queue::assertPushed(StaticWarmJob::class, 2);
        Queue::assertPushed(StaticWarmJob::class, fn ($job) => (string) $job->request->getUri() === 'https://example.com/about?page=2');
    }

    #[Test]
    public function it_purges_each_domain_in_its_own_zone()
    {
        Http::fake();
        Queue::fake([StaticWarmJob::class]);

        app(Cacher::class)->invalidateUrls(['https://example.com/about', 'https://example.fr/a-propos']);

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'zone-one') && $request['files'] === ['https://example.com/about']);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'zone-two') && $request['files'] === ['https://example.fr/a-propos']);
    }

    #[Test]
    public function it_skips_purging_urls_on_hosts_without_a_zone()
    {
        Http::fake();
        Log::spy();
        Queue::fake([StaticWarmJob::class]);

        $cacher = app(Cacher::class);
        $cacher->cacheUrl('a', '/about', 'https://example.org');

        $cacher->invalidateUrls(['https://example.org/about', 'https://example.com/about']);

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => $request['files'] === ['https://example.com/about']);

        Log::shouldHaveReceived('warning')->once()->with("Not purging [https://example.org/about] from Cloudflare, as its host isn't in the `zones` config.");

        $this->assertEmpty($cacher->getUrls('https://example.org'));
    }

    #[Test]
    public function it_batches_purges_into_requests_of_100_urls()
    {
        Http::fake();
        Queue::fake([PurgeCloudflareCache::class, StaticWarmJob::class]);

        $urls = collect(range(1, 150))->map(fn ($i) => "https://example.com/page-{$i}")->all();

        app(Cacher::class)->invalidateUrls($urls);

        app()->call([new PurgeCloudflareCache('zone-one'), 'handle']);

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request) => count($request['files']) === 100);
        Http::assertSent(fn (ClientRequest $request) => count($request['files']) === 50);
    }

    #[Test]
    public function it_keeps_unpurged_urls_for_later_when_cloudflare_rate_limits_the_purge()
    {
        Http::fakeSequence()
            ->push(['success' => true])
            ->push(['success' => false], 429, ['Retry-After' => '30'])
            ->push(['success' => true]);

        Queue::fake([StaticWarmJob::class]);

        $urls = collect(range(1, 250))->map(fn ($i) => "https://example.com/page-{$i}")->all();

        PurgeCloudflareCache::add('zone-one', $urls);

        $pending = Cache::get('cloudflare-purge:zone-one');

        $this->assertCount(150, $pending);
        $this->assertEquals('https://example.com/page-101', $pending[0]);

        Queue::assertPushed(StaticWarmJob::class, 100);
    }

    #[Test]
    public function it_purges_every_configured_host_when_the_static_cache_is_cleared()
    {
        Http::fake();

        app(Cacher::class)->cacheUrl('a', '/about', 'https://example.com');

        StaticCache::flush();

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'zone-one') && $request['hosts'] === ['example.com', 'www.example.com']);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'zone-two') && $request['hosts'] === ['example.fr']);

        $this->assertEmpty(app(Cacher::class)->getUrls('https://example.com'));
    }
}
