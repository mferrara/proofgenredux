<?php

namespace App\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Console\StatusCommand;
use Laravel\Horizon\Console\TerminateCommand;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Register Horizon commands for use in HTTP requests
        $this->registerHorizonCommandsForHttpRequests();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register Horizon commands for use in HTTP requests.
     * This allows using Artisan::call('horizon:status') and other Horizon commands
     * within HTTP requests instead of only in CLI processes.
     */
    protected function registerHorizonCommandsForHttpRequests(): void
    {
        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([
                StatusCommand::class,
                TerminateCommand::class,
            ]);
        });
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user) {
            return in_array($user->email, [
                //
            ]);
        });
    }
}
