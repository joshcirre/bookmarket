<?php

namespace App\Providers;

use App\Listeners\AddUserToDefaultOrganization;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Add new users to the default WorkOS organization for RBAC
        Event::listen(Registered::class, AddUserToDefaultOrganization::class);
    }
}
