<?php

namespace App\Providers;

use App\Commands\DeployCommand;
use App\Commands\LaunchCommand;
use App\Commands\LaunchMySQLCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                LaunchCommand::class,
                DeployCommand::class,
                LaunchMySQLCommand::class
            ]);
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
