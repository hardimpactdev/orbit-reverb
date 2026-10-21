<?php

declare(strict_types=1);

namespace App\Reverb;

use App\Reverb\Protocol\ManagedConnection;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;

final class Connections
{
    /** @var array<int, ManagedConnection> */
    private array $connections = [];

    public function add(ManagedConnection $connection): void
    {
        $this->connections[spl_object_id($connection)] = $connection;
    }

    public function remove(ManagedConnection $connection): void
    {
        unset($this->connections[spl_object_id($connection)]);
    }

    /** @param array<string, int> $apps */
    public function revoke(array $apps): void
    {
        $affected = array_filter($this->connections, fn (ManagedConnection $connection): bool => isset($apps[$connection->app()->id()]));
        foreach ($affected as $connection) {
            $connection->markRevoked($apps[$connection->app()->id()]);
        }
        foreach ($affected as $connection) {
            app(ChannelManager::class)->for($connection->app())->unsubscribeFromAll($connection);
            $connection->terminate();
        }
    }

    public function hasClosing(): bool
    {
        foreach ($this->connections as $connection) {
            if ($connection->revoked()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->connections as $connection) {
            $id = $connection->app()->id();
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }

    public function shutdown(): void
    {
        $apps = [];
        foreach ($this->connections as $connection) {
            $apps[$connection->app()->id()] = 1012;
        }
        $this->revoke($apps);
    }
}
