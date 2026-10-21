<?php

declare(strict_types=1);

namespace App\Reverb\Protocol;

use Laravel\Reverb\Servers\Reverb\Http\Connection;
use Laravel\Reverb\Servers\Reverb\Http\Router;
use Psr\Http\Message\RequestInterface;

final class ManagedRouter extends Router
{
    public function __construct(private readonly Router $router, private readonly string $notificationPath) {}

    public function dispatch(RequestInterface $request, Connection $connection): mixed
    {
        if (rawurldecode($request->getUri()->getPath()) === $this->notificationPath && $request->hasHeader('Upgrade')) {
            $this->close($connection, 400, 'WebSocket upgrade is not supported on this endpoint.');

            return null;
        }

        return $this->router->dispatch($request, $connection);
    }
}
