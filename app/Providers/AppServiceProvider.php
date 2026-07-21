<?php

namespace App\Providers;

use App\Services\RbacService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RbacService::class, fn () => new RbacService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::if('hasFeature', function (string $feature) {
            $user = auth()->user();
            if (! $user) {
                return false;
            }

            return app(RbacService::class)->hasFeature($user->role, $feature);
        });

        Blade::if('isSuperAdmin', function () {
            $user = auth()->user();

            return $user && $user->role === 'super-admin';
        });
    }
}
