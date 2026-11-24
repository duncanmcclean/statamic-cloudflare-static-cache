<?php

namespace DuncanMcClean\CloudflareStaticCache;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class Cloudflare
{
    public function __construct(private string $token) {}

    public function purge(string $zone, array $body): void
    {
        $this->request()
            ->post("zones/{$zone}/purge_cache", $body)
            ->throw();
    }

    public function cacheRules(string $zone): array
    {
        return $this->rules($zone, 'http_request_cache_settings');
    }

    public function updateCacheRules(string $zone, array $rules): void
    {
        $this->updateRules($zone, 'http_request_cache_settings', $rules);
    }

    public function cacheResponseRules(string $zone): array
    {
        return $this->rules($zone, 'http_response_cache_settings');
    }

    public function updateCacheResponseRules(string $zone, array $rules): void
    {
        $this->updateRules($zone, 'http_response_cache_settings', $rules);
    }

    private function rules(string $zone, string $phase): array
    {
        $response = $this->request()->get("zones/{$zone}/rulesets/phases/{$phase}/entrypoint");

        if ($response->notFound()) {
            return [];
        }

        return $response->throw()->json('result.rules') ?? [];
    }

    private function updateRules(string $zone, string $phase, array $rules): void
    {
        $this->request()
            ->put("zones/{$zone}/rulesets/phases/{$phase}/entrypoint", ['rules' => $rules])
            ->throw();
    }

    public function enableTieredCache(string $zone): void
    {
        $this->request()
            ->patch("zones/{$zone}/argo/tiered_caching", ['value' => 'on'])
            ->throw();

        $this->request()
            ->patch("zones/{$zone}/cache/tiered_cache_smart_topology_enable", ['value' => 'on'])
            ->throw();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.cloudflare.com/client/v4')->withToken($this->token);
    }
}
