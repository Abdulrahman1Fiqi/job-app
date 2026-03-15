<?php

namespace App\Providers;

use Illuminate\Auth\Events\Login;
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
        $this->loadMigrationsFrom(base_path('../job-backoffice/database/migrations'));

       Event::listen(Login::class, function ($event) {
        \Illuminate\Support\Facades\Log::info('Login event fired for user: ' . $event->user->id);
        $event->user->update(['last_login_at' => now()]);
        \Illuminate\Support\Facades\Log::info('last_login_at updated to: ' . $event->user->last_login_at);
    });
        
    }
}
