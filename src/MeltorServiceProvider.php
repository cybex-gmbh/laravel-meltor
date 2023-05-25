<?php

namespace Meltor;

use Config;
use Illuminate\Support\ServiceProvider;
use Meltor\Commands\MeltorDiff;
use Meltor\Commands\MeltorGenerate;
use Meltor\Commands\MeltorRestore;
use Meltor\Commands\MeltorTestMigration;

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
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/meltor.php' => config_path('meltor.php'),
            ], 'config');

            // Registering package commands.
            $this->commands([
                MeltorGenerate::class,
                MeltorDiff::class,
                MeltorRestore::class,
                MeltorTestMigration::class,
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

        // Add a new database connection based on the target database connection
        $connectionName = Config::get('meltor.connection.schema');
        $connection = Config::get('database.connections.' . $connectionName);

        if (!$connection) {
            throw new \Exception(sprintf('DB connection "%s" not found. Please refer to the readme regarding access to the MySQL information schema.', $connectionName));
        }

        $connection['database'] = 'information_schema';

        Config::set('database.connections.meltor_information_schema', $connection);

        // Register the main class to use with the facade
        $this->app->singleton('meltor', function () {
            return new Meltor;
        });
    }
}
