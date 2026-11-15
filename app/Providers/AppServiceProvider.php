<?php

namespace App\Providers;

use App\Engines\EngineRegistry;
use App\Engines\GcsRawResponseStore;
use App\Engines\RawResponseStore;
use App\Tenancy\ActingAs;
use App\Tenancy\TenantAccess;
use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: reset between requests and between queued jobs, so a tenant never leaks into the next one.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(ActingAs::class);
        $this->app->scoped(TenantAccess::class);
        $this->app->scoped(EngineRegistry::class, EngineRegistry::discover(...));
        $this->app->bind(RawResponseStore::class, GcsRawResponseStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
