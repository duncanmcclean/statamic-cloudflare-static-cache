<?php

namespace DuncanMcClean\CloudflareStaticCache\Commands;

use DuncanMcClean\CloudflareStaticCache\CloudflareCacher;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Statamic\Console\RunsInPlease;
use Statamic\StaticCaching\Cacher;

class SetupCloudflare extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:cloudflare:setup';

    protected $description = 'Adds the cache rules and enables Tiered Cache on your Cloudflare zones.';

    public function handle(): int
    {
        $cacher = app(Cacher::class);

        if (! $cacher instanceof CloudflareCacher) {
            $this->components->error('The static caching strategy is not using the [cloudflare] driver.');

            return self::FAILURE;
        }

        collect($cacher->zones())
            ->mapToGroups(fn ($zone, $host) => [$zone => $host])
            ->each(function (Collection $hosts, string $zone) use ($cacher) {
                $this->components->task(
                    "Adding cache rules for {$hosts->implode(', ')}",
                    fn () => $this->addCacheRules($cacher, $zone, $hosts)
                );

                $this->components->task(
                    "Enabling Tiered Cache for {$hosts->implode(', ')}",
                    fn () => $cacher->cloudflare()->enableTieredCache($zone)
                );
            });

        return self::SUCCESS;
    }

    private function addCacheRules(CloudflareCacher $cacher, string $zone, Collection $hosts): void
    {
        $rules = $this->cacheRules($cacher, $hosts);

        $existingRules = collect($cacher->cloudflare()->cacheRules($zone))
            ->reject(fn ($rule) => in_array($rule['ref'] ?? null, Arr::pluck($rules, 'ref')))
            ->map(fn ($rule) => Arr::except($rule, ['version', 'last_updated']))
            ->values()
            ->all();

        $cacher->cloudflare()->updateCacheRules($zone, [...$existingRules, ...$rules]);
    }

    private function cacheRules(CloudflareCacher $cacher, Collection $hosts): array
    {
        $hostExpression = 'http.host in {'.$hosts->map(fn ($host) => "\"{$host}\"")->implode(' ').'}';

        $cacheSettings = [
            'cache' => true,
            'edge_ttl' => ['mode' => 'respect_origin'],
            'browser_ttl' => ['mode' => 'respect_origin'],
        ];

        if ($cacher->config('ignore_query_strings')) {
            $cacheSettings['cache_key'] = ['custom_key' => ['query_string' => ['exclude' => ['all' => true]]]];
        }

        return [
            [
                'ref' => 'statamic_cache_pages',
                'description' => 'Statamic: make pages eligible for cache',
                'expression' => "({$hostExpression})",
                'action' => 'set_cache_settings',
                'action_parameters' => $cacheSettings,
            ],
            [
                'ref' => 'statamic_bypass_logged_in',
                'description' => 'Statamic: bypass the cache for logged-in users',
                'expression' => "({$hostExpression} and http.cookie contains \"{$cacher->loggedInCookie()}=\")",
                'action' => 'set_cache_settings',
                'action_parameters' => ['cache' => false],
            ],
        ];
    }
}
