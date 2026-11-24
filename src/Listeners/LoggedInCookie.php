<?php

namespace DuncanMcClean\CloudflareStaticCache\Listeners;

use DuncanMcClean\CloudflareStaticCache\CloudflareCacher;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cookie;
use Statamic\StaticCaching\Cacher;

class LoggedInCookie
{
    public function handleAuthenticated(Authenticated $event): void
    {
        if (! $cacher = $this->cacher()) {
            return;
        }

        $minutes = config('session.expire_on_close') ? 0 : config('session.lifetime');

        Cookie::queue($cacher->loggedInCookie(), '1', $minutes);
    }

    public function handleLogout(Logout $event): void
    {
        if (! $cacher = $this->cacher()) {
            return;
        }

        Cookie::queue(Cookie::forget($cacher->loggedInCookie()));
    }

    private function cacher(): ?CloudflareCacher
    {
        $cacher = app(Cacher::class);

        return $cacher instanceof CloudflareCacher ? $cacher : null;
    }
}
