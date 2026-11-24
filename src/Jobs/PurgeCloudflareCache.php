<?php

namespace DuncanMcClean\CloudflareStaticCache\Jobs;

use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Statamic\Console\Commands\StaticWarmJob;
use Statamic\StaticCaching\Cacher;

class PurgeCloudflareCache implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public $tries = 10;

    public function __construct(public string $zone) {}

    public static function add(string $zone, array $urls): void
    {
        static::withPendingUrls($zone, fn ($pending) => [...$pending, ...$urls]);

        static::dispatch($zone)->delay(now()->addSeconds(10));
    }

    public function uniqueId(): string
    {
        return $this->zone;
    }

    public function handle(Cacher $cacher): void
    {
        $urls = [];

        static::withPendingUrls($this->zone, function ($pending) use (&$urls) {
            $urls = $pending;

            return [];
        });

        $chunks = array_chunk(array_values(array_unique($urls)), 100);

        foreach ($chunks as $index => $chunk) {
            try {
                $cacher->cloudflare()->purge($this->zone, ['files' => $chunk]);
            } catch (RequestException $e) {
                if ($e->response->status() !== 429) {
                    throw $e;
                }

                $unpurged = array_merge(...array_slice($chunks, $index));

                static::withPendingUrls($this->zone, fn ($pending) => [...$pending, ...$unpurged]);

                $this->release((int) $e->response->header('Retry-After') ?: 60);

                return;
            }

            $this->warm($chunk);
        }
    }

    private function warm(array $urls): void
    {
        $job = config('statamic.static_caching.warm_job') ?? StaticWarmJob::class;

        $clientConfig = ['verify' => ! config('statamic.static_caching.warm_insecure') && ! app()->isLocal()];

        foreach ($urls as $url) {
            $job::dispatch(new Request('GET', $url), $clientConfig)
                ->onConnection(config('statamic.static_caching.warm_queue_connection') ?? config('queue.default'))
                ->onQueue(config('statamic.static_caching.warm_queue'))
                ->delay(now()->addSeconds(5));
        }
    }

    private static function withPendingUrls(string $zone, callable $callback): void
    {
        $key = "cloudflare-purge:{$zone}";

        Cache::lock("{$key}:lock", 10)->block(10, function () use ($key, $callback) {
            Cache::forever($key, $callback(Cache::get($key, [])));
        });
    }
}
