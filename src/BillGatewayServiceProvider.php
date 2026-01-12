<?php

namespace Aelura\BillGateway;

use Illuminate\Support\ServiceProvider;

class BillGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing.php', 'billing');

        $this->app->singleton(BillGatewayManager::class, function ($app) {
            return new BillGatewayManager($app);
        });

        $this->app->alias(BillGatewayManager::class, 'bill-gateway');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/billing.php' => config_path('billing.php'),
            ], 'billing-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'billing-migrations');

            $this->commands([
                Console\SyncBillsCommand::class,
            ]);
        }
    }
}
