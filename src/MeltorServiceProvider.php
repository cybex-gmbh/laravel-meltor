<?php

namespace Meltor;

use Meltor\Commands\MeltorCommand;
use Illuminate\Support\ServiceProvider;

class MeltorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        /*
         * Optional methods to load your package assets
         */
         $this->loadViewsFrom(__DIR__.'/../resources/views', 'meltor');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/meltor.php' => config_path('meltor.php'),
            ], 'config');

            $this->publishes([
                __DIR__ . '/../stubs/'
            ]);

            // Registering package commands.
            $this->commands([
                MeltorCommand::class
            ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/meltor.php', 'meltor');
        $this->mergeConfigFrom(__DIR__ . '/../config/meltor-templates.php', 'meltor-templates');

        // Register the main class to use with the facade
        $this->app->singleton('meltor', function () {
            return new Meltor;
        });
    }
}
