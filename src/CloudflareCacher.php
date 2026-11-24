<?php

namespace DuncanMcClean\CloudflareStaticCache;

use DuncanMcClean\CloudflareStaticCache\Jobs\PurgeCloudflareCache;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Statamic\Events\UrlInvalidated;
use Statamic\StaticCaching\Cachers\FileCacher;

class CloudflareCacher extends FileCacher
{
    public function cachePage(Request $request, $content)
    {
        $url = $this->getUrl($request);

        if ($this->isExcluded($url) || auth()->check()) {
            return;
        }

        $handled = false;

        Event::listen(ResponsePrepared::class, function (ResponsePrepared $event) use ($url, &$handled) {
            if ($handled) {
                return;
            }

            $handled = true;

            $event->response->headers->remove('Set-Cookie');
            $event->response->headers->set('Cache-Control', 'public, max-age=0, must-revalidate');
            $event->response->headers->set('CDN-Cache-Control', 'max-age='.$this->edgeTtl());

            $this->cacheUrl($this->makeHash($url), ...$this->getPathAndDomain($url));
        });
    }

    private function edgeTtl(): int
    {
        return ($this->getDefaultExpiration() ?: 60 * 24 * 365) * 60;
    }

    public function hasCachedPage(Request $request)
    {
        return false;
    }

    public function getCachedPage(Request $request)
    {
        return null;
    }

    public function flush()
    {
        collect($this->zones())
            ->mapToGroups(fn ($zone, $host) => [$zone => $host])
            ->each(fn ($hosts, $zone) => $this->cloudflare()->purge($zone, ['hosts' => $hosts->all()]));

        $this->flushUrls();

        $this->flushDedicatedStore();
    }

    public function invalidateUrl($url, $domain = null)
    {
        if ($domain === null) {
            [$url, $domain] = $this->getPathAndDomain($url);
        }

        $cachedUrls = $this
            ->getUrls($domain)
            ->filter(fn ($value) => $value === $url || str_starts_with($value, $url.'?'));

        $this->forgetUrls($cachedUrls->keys()->all(), $domain);

        if ($zone = $this->zone($domain)) {
            PurgeCloudflareCache::add(
                zone: $zone,
                urls: $cachedUrls
                    ->push($url)
                    ->unique()
                    ->map(fn ($path) => $domain.$path)
                    ->all(),
            );
        } else {
            Log::warning("Not purging [{$domain}{$url}] from Cloudflare, as its host isn't in the `zones` config.");
        }

        UrlInvalidated::dispatch($url, $domain);
    }

    private function zone(string $domain): ?string
    {
        return $this->zones()[parse_url($domain, PHP_URL_HOST)] ?? null;
    }

    public function refreshUrl($url, $domain = null)
    {
        $this->invalidateUrl($url, $domain);
    }

    public function zones(): array
    {
        return $this->config('zones', []);
    }

    public function loggedInCookie(): string
    {
        return $this->config('logged_in_cookie', 'statamic_logged_in');
    }

    public function cloudflare(): Cloudflare
    {
        return new Cloudflare((string) $this->config('token'));
    }
}
