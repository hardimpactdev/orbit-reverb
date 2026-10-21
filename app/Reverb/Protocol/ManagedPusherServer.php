<?php

declare(strict_types=1);

namespace App\Reverb\Protocol;

use App\Reverb\Connections;
use App\Reverb\Control\RefreshCoordinator;
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
        $this->channels->for($from->app());

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
