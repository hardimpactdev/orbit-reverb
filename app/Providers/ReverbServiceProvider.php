<?php

declare(strict_types=1);

namespace App\Providers;

use App\Reverb\Connections;
use App\Reverb\Console\Commands\ReverbManagerCommand;
use App\Reverb\Control\RefreshCoordinator;
use App\Reverb\Measurements;
use App\Reverb\Protocol\ManagedIncomingMessageHandler;
use App\Reverb\Protocol\ManagedPusherServer;
use App\Reverb\ReverbManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\ApplicationManager;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Protocols\Pusher\Server;
use Laravel\Reverb\Servers\Reverb\Contracts\PubSubIncomingMessageHandler;

class ReverbServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Connections::class);
        $this->app->singleton(Measurements::class);
        $this->app->singleton(PubSubIncomingMessageHandler::class, ManagedIncomingMessageHandler::class);
        $this->app->singleton(RefreshCoordinator::class);
        $this->app->singleton(Server::class, ManagedPusherServer::class);
        $this->app->singleton(ReverbManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(ApplicationManager::class)->extend('orbit', fn (Application $app): ReverbManager => $app->make(ReverbManager::class));
        config()->set('reverb.apps.provider', 'orbit');
        $this->app->make(Dispatcher::class)->listen([MessageReceived::class, MessageSent::class], $this->app->make(Measurements::class)->record(...));

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ReverbManagerCommand::class,
        ]);
    }
}
