<?php

declare(strict_types=1);

namespace App\Reverb\Protocol;

use App\Reverb\Control\NotificationController;
use App\Reverb\Control\RegistryApplyController;
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
        return new ManagedRouter(parent::makePusherRouter($path), rtrim($path, '/').'/internal/orbit/');
    }

    protected static function pusherRoutes(string $path): RouteCollection
    {
        $routes = parent::pusherRoutes('');
        $routes->add('sockets', Route::get('/app/{appKey}', new ManagedPusherController(app(Server::class), app(ApplicationProvider::class))));
        $routes->add('registry_changed', Route::post('/internal/orbit/registry-changed', app(NotificationController::class)));
        $routes->add('registry_apply', Route::post('/internal/orbit/registry/apply', app(RegistryApplyController::class)));
        $routes->addPrefix($path);

        return $routes;
    }
}
