<?php

declare(strict_types=1);

namespace HardImpact\ReverbManager\Tests\Support;

use Ratchet\RFC6455\Messaging\Frame;
use RuntimeException;

/** A raw client with no reconnect path, so continuity failures cannot be hidden. */
final class WireClient
{
    private mixed $stream;

    public readonly string $socketId;

    public function __construct(int $port, string $key)
    {
        $this->stream = stream_socket_client('tcp://127.0.0.1:'.$port, timeout: 2);
        stream_set_timeout($this->stream, 3);
        fwrite($this->stream, "GET /app/{$key}?protocol=7&client=js&version=8.6.0 HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".base64_encode(random_bytes(16))."\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $headers = '';
        while (! str_ends_with($headers, "\r\n\r\n")) {
            $headers .= $this->read(1);
        }

        if (! str_contains($headers, '101 Switching Protocols')) {
            throw new RuntimeException('Handshake failed: '.$headers);
        }
        $event = $this->event();
        $this->socketId = json_decode($event['data'], true, flags: JSON_THROW_ON_ERROR)['socket_id'];
    }

    public function send(array $event): void
    {
        $frame = new Frame(json_encode($event, JSON_THROW_ON_ERROR));
        $frame->maskPayload();
        fwrite($this->stream, $frame->getContents());
    }

    public function frame(): array
    {
        $header = unpack('Cfirst/Csecond', $this->read(2));
        $length = $header['second'] & 127;

        if ($length === 126) {
            $length = unpack('nlength', $this->read(2))['length'];
        } elseif ($length === 127) {
            throw new RuntimeException('Unexpected large fixture frame.');
        }

        return [$header['first'] & 15, $this->read($length)];
    }

    public function event(): array
    {
        [$opcode, $payload] = $this->frame();

        if ($opcode !== 1) {
            throw new RuntimeException('Expected a message, received opcode '.$opcode);
        }

        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    public function closeCode(): int
    {
        [$opcode, $payload] = $this->frame();

        if ($opcode !== 8) {
            throw new RuntimeException('Expected a close frame.');
        }

        return unpack('ncode', substr($payload, 0, 2))['code'];
    }

    private function read(int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $chunk = fread($this->stream, $length - strlen($result));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Socket closed or timed out.');
            }
            $result .= $chunk;
        }

        return $result;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
