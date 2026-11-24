<?php

return [
    'driver' => 'cloudflare',
    'token' => env('CLOUDFLARE_API_TOKEN'),
    'zones' => [
        parse_url(config('app.url'), PHP_URL_HOST) => env('CLOUDFLARE_ZONE_ID'),
    ],
];
