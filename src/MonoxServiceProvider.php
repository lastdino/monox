<?php

namespace Lastdino\Monox;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Illuminate\Support\Facades\Route;

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
    public function boot(): void
    {
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

        $this->app->booted(function() {
            if (Route::hasMacro('livewire')) {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            }
        });
    }

    protected function registerLivewireComponents(): void
    {
        Livewire::addNamespace('monox', __DIR__.'/../resources/views/pages');

        // もし公開されたビューがあれば、そちらを優先するようにLivewireコンポーネントを再登録
        $publishedPath = resource_path('views/vendor/monox/pages');
        if (is_dir($publishedPath)) {
            $files = array_diff(scandir($publishedPath), ['.', '..']);
            if (count($files) > 0) {
                Livewire::addNamespace('monox', $publishedPath);
            }
        }
    }
}
