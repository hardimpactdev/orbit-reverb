<?php

declare(strict_types=1);

namespace App\Reverb\Protocol;

use Laravel\Reverb\Connection as ReverbConnection;
use Laravel\Reverb\Exceptions\InvalidApplication;
use Laravel\Reverb\Protocols\Pusher\Http\Controllers\PusherController;
use Laravel\Reverb\Servers\Reverb\Connection;
use Psr\Http\Message\RequestInterface;
use Ratchet\RFC6455\Messaging\Frame;

final class ManagedPusherController extends PusherController
{
    protected function connection(RequestInterface $request, Connection $connection, string $key): ?ReverbConnection
    {
        try {
            $application = $this->applications->findByKey($key);
        } catch (InvalidApplication) {
            $connection->send(json_encode(['event' => 'pusher:error', 'data' => json_encode(['code' => 4001, 'message' => 'Application does not exist'], JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR));
            $connection->close(new Frame(pack('n', 4001).'Application removed', opcode: Frame::OP_CLOSE));

            return null;
        }

        return new ManagedConnection($connection, $application, $request->getHeader('Origin')[0] ?? null);
    }
}
