<?php

namespace App\Providers;

use App\Domain\Celebration\CelebrationBag;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One bag per request, shared by everything that can earn a reward, so
        // the controller can report every badge and milestone the request
        // produced no matter how deep in the call stack it happened.
        $this->app->scoped(CelebrationBag::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
