<?php

namespace Zus1\LaravelBaseRepository\Providers;

use Illuminate\Support\ServiceProvider;

class LaravelBaseRepositoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/laravel-base-repository.php' => config_path('courier.php'),
        ]);
    }
}