<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Laravel;

use Illuminate\Support\ServiceProvider;
use Krafsys\PaymentManager\PaymentManager;

final class PaymentManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/payment-manager.php', 'payment-manager');

        $this->app->singleton(PaymentManager::class, static function ($app) {
            return new PaymentManager((array) $app['config']->get('payment-manager', []));
        });

        $this->app->alias(PaymentManager::class, 'payment-manager');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/payment-manager.php' => config_path('payment-manager.php'),
            ], 'payment-manager-config');
        }
    }

    /**
     * @return string[]
     */
    public function provides(): array
    {
        return [PaymentManager::class, 'payment-manager'];
    }
}
