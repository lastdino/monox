<?php

namespace Lastdino\Monox;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Lastdino\Monox\Http\Middleware\EnsurePermissionsAreConfigured;
use Lastdino\Monox\Http\Middleware\VerifyApiKey;
use Lastdino\Monox\Models\StockMovement;
use Lastdino\Monox\Observers\StockMovementObserver;
use Livewire\Livewire;

class MonoxServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/monox.php',
            'monox'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(Router $router): void
    {
        $router->aliasMiddleware('monox.ensure-permissions', EnsurePermissionsAreConfigured::class);
        $router->aliasMiddleware('monox.api-key', VerifyApiKey::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'monox');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/monox'),
            ], 'monox-views');

            $this->publishes([
                __DIR__.'/../dist/monox.css' => public_path('vendor/monox/monox.css'),
            ], 'monox-assets');

            $this->publishes([
                __DIR__.'/../config/monox.php' => config_path('monox.php'),
            ], 'monox-config');

        }

        $this->registerLivewireComponents();

        StockMovement::observe(StockMovementObserver::class);

        $this->app->booted(function () {
            if (Route::hasMacro('livewire')) {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            }
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
    }

    protected function registerLivewireComponents(): void
    {
        Livewire::addNamespace('monox_component', __DIR__.'/../resources/views/components');
        Livewire::addNamespace('monox', __DIR__.'/../resources/views/pages');

        // もし公開されたビューがあれば、そちらを優先するようにLivewireコンポーネントを再登録
        $publishedPath = resource_path('views/vendor/monox/pages');
        if (is_dir($publishedPath)) {
            $files = array_diff(scandir($publishedPath), ['.', '..']);
            if (count($files) > 0) {
                Livewire::addNamespace('monox', $publishedPath);
            }
        }
        $publishedPath = resource_path('views/vendor/monox/components');
        if (is_dir($publishedPath)) {
            $files = array_diff(scandir($publishedPath), ['.', '..']);
            if (count($files) > 0) {
                Livewire::addNamespace('monox_component', $publishedPath);
            }
        }
    }
}
