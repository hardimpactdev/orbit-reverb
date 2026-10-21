<?php

declare(strict_types=1);

namespace HardImpact\ReverbManager\Protocol;

use HardImpact\ReverbManager\Connections;
use HardImpact\ReverbManager\Control\RefreshCoordinator;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Protocols\Pusher\Server;
use React\EventLoop\Loop;

final class ManagedPusherServer extends Server
{
    public function open(Connection $connection): void
    {
        if ($connection instanceof ManagedConnection) {
            app(Connections::class)->add($connection);
        }
        parent::open($connection);
    }

    public function message(Connection $from, string $message): void
    {
        if ($from instanceof ManagedConnection && $from->revoked()) {
            return;
        }
        parent::message($from, $message);
    }

    public function close(Connection $connection): void
    {
        try {
            parent::close($connection);
        } finally {
            if ($connection instanceof ManagedConnection) {
                app(Connections::class)->remove($connection);

                if ($connection->revoked()) {
                    Loop::futureTick(app(RefreshCoordinator::class)->report(...));
                }
            }
        }
    }
}
