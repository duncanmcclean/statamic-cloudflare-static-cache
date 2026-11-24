<?php

namespace Tests;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cookie;
use PHPUnit\Framework\Attributes\Test;

class LoggedInCookieTest extends TestCase
{
    #[Test]
    public function it_sets_the_logged_in_cookie_for_as_long_as_the_session_lasts()
    {
        config()->set('session.lifetime', 90);

        event(new Authenticated('web', new User));

        $cookie = Cookie::queued('statamic_logged_in');

        $this->assertNotNull($cookie);
        $this->assertGreaterThan(now()->addMinutes(89)->getTimestamp(), $cookie->getExpiresTime());
    }

    #[Test]
    public function it_forgets_the_logged_in_cookie_on_logout()
    {
        event(new Logout('web', new User));

        $this->assertLessThan(now()->getTimestamp(), Cookie::queued('statamic_logged_in')->getExpiresTime());
    }

    #[Test]
    public function it_does_not_set_the_logged_in_cookie_when_another_strategy_is_used()
    {
        config()->set('statamic.static_caching.strategy', 'half');
        config()->set('statamic.static_caching.strategies.half', ['driver' => 'application']);

        event(new Authenticated('web', new User));

        $this->assertNull(Cookie::queued('statamic_logged_in'));
    }
}
