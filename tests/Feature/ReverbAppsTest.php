<?php

use Laravel\Reverb\Contracts\ApplicationProvider;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Symfony\Component\Process\Process;

// phpunit.xml sets REVERB_APPS to a Gateway app and a Hauzer app.

it('serves one Reverb app per entry of REVERB_APPS', function () {
    $apps = app(ApplicationProvider::class);

    expect($apps->all())->toHaveCount(2)
        ->and($apps->findByKey('gateway-key')->secret())->toBe('gateway-secret')
        ->and($apps->findById('hauzer-id')->secret())->toBe('hauzer-secret')
        ->and($apps->findById('hauzer-id')->acceptClientEventsFrom())->toBe('members');
});

it('lets an app publish only with its own secret', function () {
    $port = reverb_test_free_port();
    $server = new Process(
        [PHP_BINARY, 'artisan', 'reverb:start', '--host=127.0.0.1', "--port={$port}"],
        base_path(),
        ['APP_KEY' => 'base64:'.base64_encode(random_bytes(32))],
    );
    $server->start();

    try {
        reverb_test_wait_for_port($port);

        expect(reverb_test_client('hauzer-key', 'hauzer-secret', 'hauzer-id', $port)->trigger('orbit', 'ping', []))
            ->toBeObject();

        // Hauzer's credentials against the Gateway's app ID: Reverb checks the signature with the Gateway's secret.
        expect(fn () => reverb_test_client('hauzer-key', 'hauzer-secret', 'gateway-id', $port)->trigger('orbit', 'ping', []))
            ->toThrow(fn (ApiErrorException $exception) => expect($exception->getCode())->toBe(401));
    } finally {
        $server->stop();
    }
});

function reverb_test_client(string $key, string $secret, string $appId, int $port): Pusher
{
    return new Pusher($key, $secret, $appId, [
        'host' => '127.0.0.1',
        'port' => $port,
        'scheme' => 'http',
        'useTLS' => false,
    ]);
}

function reverb_test_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
}

function reverb_test_wait_for_port(int $port): void
{
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port);

        if ($connection !== false) {
            fclose($connection);

            return;
        }

        usleep(100_000);
    }

    throw new RuntimeException("Reverb did not listen on port {$port}.");
}
