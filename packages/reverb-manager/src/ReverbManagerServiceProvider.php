<?php

declare(strict_types=1);

namespace HardImpact\ReverbManager;

use HardImpact\ReverbManager\Console\Commands\ReverbManagerCommand;
use HardImpact\ReverbManager\Control\RefreshCoordinator;
use HardImpact\ReverbManager\Protocol\ManagedIncomingMessageHandler;
use HardImpact\ReverbManager\Protocol\ManagedPusherServer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\ApplicationManager;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Protocols\Pusher\Server;
use Laravel\Reverb\Servers\Reverb\Contracts\PubSubIncomingMessageHandler;

class ReverbManagerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/reverb-manager.php', 'reverb-manager');

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

        $this->publishes([
            __DIR__.'/../config/reverb-manager.php' => config_path('reverb-manager.php'),
        ], ['reverb-manager', 'reverb-manager-config']);

        $this->commands([
            ReverbManagerCommand::class,
        ]);
    }
}
