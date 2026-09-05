<?php

namespace Talivio\Sdk\Tests;

use Illuminate\Support\Facades\Http;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Talivio\Sdk\TalivioServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // Socialite is auto-discovered in a real app; testbench needs it
        // listed because the SDK's boot() registers the "talivio" driver.
        return [SocialiteServiceProvider::class, TalivioServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A URL no test stubbed must never reach the real API (it once
        // did: an un-stubbed Openprovider call answered with a live 401).
        Http::preventStrayRequests();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('talivio.telemetry_enabled', false);
        $app['config']->set('talivio.heartbeat_schedule', false);
    }
}
