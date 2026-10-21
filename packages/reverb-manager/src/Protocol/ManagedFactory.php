<?php

declare(strict_types=1);

namespace HardImpact\ReverbManager\Protocol;

use HardImpact\ReverbManager\Control\NotificationController;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Protocols\Pusher\Server;
use Laravel\Reverb\Servers\Reverb\Factory;
use Laravel\Reverb\Servers\Reverb\Http\Route;
use Laravel\Reverb\Servers\Reverb\Http\Router;
use Symfony\Component\Routing\RouteCollection;

final class ManagedFactory extends Factory
{
    public static function makePusherRouter(string $path): Router
    {
        return new ManagedRouter(parent::makePusherRouter($path), rtrim($path, '/').'/internal/orbit/registry-changed');
    }

    protected static function pusherRoutes(string $path): RouteCollection
    {
        $routes = parent::pusherRoutes('');
        $routes->add('sockets', Route::get('/app/{appKey}', new ManagedPusherController(app(Server::class), app(ApplicationProvider::class))));
        $routes->add('registry_changed', Route::post('/internal/orbit/registry-changed', app(NotificationController::class)));
        $routes->addPrefix($path);

        return $routes;
    }
}
