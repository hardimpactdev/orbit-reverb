<?php

declare(strict_types=1);

namespace App\Reverb\Protocol;

use Laravel\Reverb\Connection;
use Laravel\Reverb\Servers\Reverb\Connection as Transport;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;

final class ManagedConnection extends Connection
{
    private ?int $closeCode = null;

    private bool $ending = false;

    public function markRevoked(int $code): void
    {
        $this->closeCode ??= $code;
    }

    public function revoked(): bool
    {
        return $this->closeCode !== null;
    }

    public function send(string $message): void
    {
        if (! $this->revoked()) {
            parent::send($message);
        }
    }

    public function terminate(): void
    {
        if ($this->ending) {
            return;
        }
        $this->ending = true;
        $this->armCloseDeadline();

        if ($this->closeCode === null) {
            parent::terminate();

            return;
        }
        $reason = match ($this->closeCode) {
            4001 => 'Application removed',
            4201 => 'Credentials rotated',
            default => 'Service restart',
        };
        $this->connection->close(new Frame(pack('n', $this->closeCode).$reason, opcode: Frame::OP_CLOSE));
    }

    private function armCloseDeadline(): void
    {
        if ($this->connection instanceof Transport) {
            $transport = $this->connection->connection;
            $timer = Loop::addTimer(2, static function () use ($transport): void {
                // Http\Connection::close ends the stream; its proxy exposes the underlying force-close.
                $transport->__call('close', []);
            });
            $transport->__call('on', ['close', static function () use ($timer): void {
                Loop::cancelTimer($timer);
            }]);
        }
    }
}
