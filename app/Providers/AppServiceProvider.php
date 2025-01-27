<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Tranciti\TrancitiService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /* $this->app->singleton(TrancitiService::class, function ($app) {
            return new TrancitiService();
        }); */
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

}
