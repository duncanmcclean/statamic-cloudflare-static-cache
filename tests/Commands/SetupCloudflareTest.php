<?php

namespace Tests\Commands;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\StaticCaching\StaticCacheManager;
use Tests\TestCase;

class SetupCloudflareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            $this->cacheRulesUrl('zone-one') => Http::sequence()
                ->push(['result' => ['rules' => [
                    ['id' => 'abc', 'version' => '3', 'last_updated' => 'today', 'ref' => 'custom', 'expression' => 'true', 'action' => 'set_cache_settings'],
                    ['id' => 'def', 'version' => '1', 'ref' => 'statamic_bypass_logged_in', 'expression' => 'old', 'action' => 'set_cache_settings'],
                ]]])
                ->push(['success' => true]),
            $this->cacheRulesUrl('zone-two') => Http::sequence()
                ->push(['success' => false], 404)
                ->push(['success' => true]),
            '*' => Http::response(['success' => true]),
        ]);
    }

    private function cacheRulesUrl(string $zone): string
    {
        return "https://api.cloudflare.com/client/v4/zones/{$zone}/rulesets/phases/http_request_cache_settings/entrypoint";
    }

    #[Test]
    public function it_adds_the_cache_rules_after_existing_rules_with_the_bypass_rule_last()
    {
        $this->artisan('statamic:cloudflare:setup')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'PUT' || $request->url() !== $this->cacheRulesUrl('zone-one')) {
                return false;
            }

            $rules = $request['rules'];

            return count($rules) === 3
                && $rules[0] === ['id' => 'abc', 'ref' => 'custom', 'expression' => 'true', 'action' => 'set_cache_settings']
                && $rules[1]['ref'] === 'statamic_cache_pages'
                && $rules[1]['expression'] === '(http.host in {"example.com" "www.example.com"})'
                && $rules[2]['ref'] === 'statamic_bypass_logged_in'
                && $rules[2]['expression'] === '(http.host in {"example.com" "www.example.com"} and http.cookie contains "statamic_logged_in=")'
                && $rules[2]['action_parameters'] === ['cache' => false];
        });
    }

    #[Test]
    public function it_creates_the_cache_rules_on_zones_without_any()
    {
        $this->artisan('statamic:cloudflare:setup')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === $this->cacheRulesUrl('zone-two')
            && array_column($request['rules'], 'ref') === ['statamic_cache_pages', 'statamic_bypass_logged_in']);
    }

    #[Test]
    public function it_ignores_query_strings_in_the_cache_key_when_statamic_does()
    {
        config()->set('statamic.static_caching.ignore_query_strings', true);
        app()->forgetInstance(StaticCacheManager::class);

        $this->artisan('statamic:cloudflare:setup')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === $this->cacheRulesUrl('zone-two')
            && $request['rules'][0]['action_parameters']['cache_key'] === ['custom_key' => ['query_string' => ['exclude' => ['all' => true]]]]);
    }

    #[Test]
    public function it_enables_smart_tiered_cache()
    {
        $this->artisan('statamic:cloudflare:setup')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-one/argo/tiered_caching');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-two/cache/tiered_cache_smart_topology_enable');
    }

    #[Test]
    public function it_fails_when_the_cloudflare_strategy_is_not_in_use()
    {
        config()->set('statamic.static_caching.strategy', 'null');

        $this->artisan('statamic:cloudflare:setup')->assertFailed();

        Http::assertNothingSent();
    }
}
