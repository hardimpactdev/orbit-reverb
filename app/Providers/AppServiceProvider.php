<?php

namespace App\Providers;

use App\Reverb\AppScopedPusherServer;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\Protocols\Pusher\Server;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Server::class, AppScopedPusherServer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
