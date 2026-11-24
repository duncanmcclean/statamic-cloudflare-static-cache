# Cloudflare Static Cache

A static caching strategy for Statamic that keeps your pages in Cloudflare's cache, rather than in HTML files (full measure) or the Laravel cache (half measure).

Statamic marks pages as cacheable, Cloudflare caches them at the edge, and your invalidation rules and `php please static:clear` purge them from Cloudflare.

## Installation

1. Require the addon:

    ```
    composer require duncanmcclean/statamic-cloudflare-static-cache
    ```

2. In Cloudflare, [create an API token](https://dash.cloudflare.com/profile/api-tokens) with these **Zone** permissions: **Cache Purge**, **Cache Settings** (called **Cache Rules** in some accounts) and **Zone Settings**, each set to **Edit**. Then copy your domain's **Zone ID** from its overview page.

3. Add them to your `.env`, and switch to the `cloudflare` strategy:

    ```
    STATAMIC_STATIC_CACHING_STRATEGY=cloudflare
    CLOUDFLARE_API_TOKEN=your-token
    CLOUDFLARE_ZONE_ID=your-zone-id
    ```

4. Set up your Cloudflare zone:

    ```
    php please cloudflare:setup
    ```

Make sure a queue worker is running, as purges are queued.

## Configuration

Out of the box, the addon caches the host in your `APP_URL`. To change anything, add a `cloudflare` strategy to `config/statamic/static_caching.php` with just the options you're changing:

```php
'strategies' => [
    'cloudflare' => [
        'zones' => [
            'example.com' => env('CLOUDFLARE_ZONE_ID'),
            'www.example.com' => env('CLOUDFLARE_ZONE_ID'),
        ],
    ],
],
```

| Option | Description |
|---|---|
| `token` | Your Cloudflare API token. |
| `zones` | Each host your site is served on, and its Cloudflare zone ID. See below. |
| `expiry` | How long Cloudflare keeps a page, in minutes. Defaults to a year, since pages are purged when they change. |
| `logged_in_cookie` | The cookie that tells Cloudflare to bypass the cache for logged-in users. Defaults to `statamic_logged_in`. |

Statamic's `exclude`, `ignore_query_strings` and `invalidation` options work as usual.

### Zones

List every host your site is served on, such as `www.example.com` as well as `example.com`. Only listed hosts are purged, and a warning is logged when a URL on any other host is invalidated.

Multisites on other domains add their own host and zone. Multisites in subdirectories, such as `example.com/fr`, share a host, so they need nothing extra.

```php
'zones' => [
    'example.com' => env('CLOUDFLARE_ZONE_ID'),
    'www.example.com' => env('CLOUDFLARE_ZONE_ID'),
    'example.fr' => env('CLOUDFLARE_FR_ZONE_ID'),
],
```

## How it works

- **Caching:** cacheable pages are sent with `Cloudflare-CDN-Cache-Control`, so Cloudflare keeps them for `expiry`, while browsers always revalidate. Cookies are stripped from those responses, since Cloudflare won't cache a response that sets one.
- **Logged-in users:** a `statamic_logged_in` cookie is set while someone is logged in, and the setup command's bypass rule skips the cache when it's present.
- **Invalidation:** invalidated URLs are collected and purged by URL in batches of 100. When Cloudflare rate limits a purge, the remaining URLs are retried later. Purged pages are then warmed using Statamic's warm job.
- **Clearing:** `static:clear` purges every configured host.
- **`{{ nocache }}` and CSRF tokens** are loaded with JavaScript, the same way as full measure.

## The setup command

For each zone, `cloudflare:setup`:

- adds a cache rule that makes your hosts' pages eligible for cache, respecting the headers Statamic sends,
- adds a rule after it that bypasses the cache for logged-in users,
- ignores query strings in the cache key when `ignore_query_strings` is enabled,
- enables Smart Tiered Cache, so a warmed page serves every Cloudflare data centre.

Existing cache rules are kept. Running the command again replaces the addon's rules rather than duplicating them.
