<?php

namespace Talivio\Sdk;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use Talivio\Sdk\Console\HeartbeatCommand;
use Talivio\Sdk\Http\Controllers\AccountDeletionController;
use Talivio\Sdk\Http\Controllers\SupportFormController;
use Talivio\Sdk\Http\Controllers\TalivioAuthController;
use Throwable;

class TalivioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/talivio.php', 'talivio');
        $this->app->singleton(ErrorReporter::class);
    }

    public function boot(): void
    {
        $this->registerSocialiteDriver();
        $this->registerRoutes();
        $this->registerErrorReporting();
        $this->registerViews();
        $this->registerPublishing();

        if ($this->app->runningInConsole()) {
            $this->commands([HeartbeatCommand::class]);
        }
    }

    private function registerSocialiteDriver(): void
    {
        Socialite::extend('talivio', function ($app) {
            $config = $app['config']['talivio'];

            return Socialite::buildProvider(TalivioProvider::class, [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect' => $config['redirect'] ?: url('/talivio/callback'),
            ]);
        });
    }

    private function registerRoutes(): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/talivio/login', [TalivioAuthController::class, 'redirect'])->name('talivio.login');
            Route::get('/talivio/callback', [TalivioAuthController::class, 'callback'])->name('talivio.callback');
            Route::post('/talivio/support', [SupportFormController::class, 'store'])
                ->middleware('throttle:5,1')
                ->name('talivio.support.store');

            Route::middleware('auth:'.config('talivio.guard'))->group(function () {
                Route::get('/talivio/link', [TalivioAuthController::class, 'link'])->name('talivio.link');
                Route::post('/talivio/unlink', [TalivioAuthController::class, 'unlink'])->name('talivio.unlink');
            });
        });

        // Server-to-server GDPR deletion callback from the hub — no session,
        // no CSRF; authenticated by HMAC signature inside the controller.
        Route::post('/talivio/account-deleted', AccountDeletionController::class)
            ->middleware('throttle:30,1')
            ->name('talivio.account-deleted');
    }

    /**
     * Hooks into the host app's own exception handler — no changes needed in
     * the product's code for error telemetry to start flowing.
     */
    private function registerErrorReporting(): void
    {
        if (! config('talivio.telemetry_enabled')) {
            return;
        }

        try {
            $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

            if ($handler instanceof ExceptionHandler) {
                $handler->reportable(function (Throwable $e) {
                    $this->app->make(ErrorReporter::class)->report($e);
                });
            }
        } catch (Throwable) {
            // Never let telemetry wiring break the host app's boot.
        }
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'talivio');
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/talivio.php' => config_path('talivio.php'),
        ], 'talivio-config');

        $this->publishes([
            __DIR__.'/../database/migrations/add_talivio_id_to_users_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_add_talivio_id_to_users_table.php'),
        ], 'talivio-migrations');
    }
}
