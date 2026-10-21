<?php

declare(strict_types=1);

use App\Reverb\Protocol\ManagedConnection;
use App\Reverb\Protocol\ManagedFactory;
use App\Reverb\Protocol\ManagedIncomingMessageHandler;
use App\Reverb\ReverbManager;
use GuzzleHttp\Psr7\Request;
use Laravel\Reverb\Servers\Reverb\Connection as Transport;
use Laravel\Reverb\Servers\Reverb\Http\Connection as HttpConnection;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;

it('rejects encoded control endpoint upgrades before opening a websocket', function (string $path) {
    $raw = Mockery::mock(ConnectionInterface::class);
    $raw->stream = fopen('php://temp', 'r+');
    $raw->shouldReceive('write')->once()->withArgs(fn (string $message): bool => str_contains($message, '400 Bad Request') && ! str_contains($message, '101 Switching Protocols'));
    $raw->shouldReceive('end')->once();
    ManagedFactory::makePusherRouter('/prefix')->dispatch(new Request('POST', $path, ['Upgrade' => 'websocket']), new HttpConnection($raw));
    fclose($raw->stream);
})->with(['/prefix/internal/orbit/registry-changed', '/prefix/%69nternal/orbit/registry-changed']);

it('bounds transport shutdown even when ordinary termination precedes revocation', function () {
    $previousLoop = Loop::get();
    $loop = Mockery::mock(LoopInterface::class);
    $timer = Mockery::mock(TimerInterface::class);
    $deadline = null;
    $loop->shouldReceive('addTimer')->once()->withArgs(function (int $seconds, Closure $callback) use (&$deadline): bool {
        $deadline = $callback;

        return $seconds === 2;
    })->andReturn($timer);
    Loop::set($loop);
    $raw = Mockery::mock(ConnectionInterface::class);
    $raw->stream = fopen('php://temp', 'r+');
    $raw->shouldReceive('on')->with('close', Mockery::type(Closure::class))->once();
    $raw->shouldReceive('end')->once();
    $raw->shouldReceive('close')->once();
    app(ReverbManager::class)->apply(registrySnapshot(1, [registryApp('a')]));
    $connection = new ManagedConnection(new Transport(new HttpConnection($raw)), app(ReverbManager::class)->findById('a'), null);
    $connection->terminate();
    $connection->markRevoked(4001);
    $connection->terminate();
    $deadline();
    Loop::set($previousLoop);
    fclose($raw->stream);
    expect($connection->revoked())->toBeTrue();
});

it('rejects delayed redis messages from removed apps and old generations even after a secret changes back', function () {
    $manager = app(ReverbManager::class);
    $manager->apply(registrySnapshot(1, [registryApp('a')]));
    $old = $manager->findById('a');
    ManagedFactory::makePusherRouter('');
    $handler = app(ManagedIncomingMessageHandler::class);
    $delivered = 0;
    $handler->listen('message', function () use (&$delivered): void {
        $delivered++;
    });
    $envelope = fn ($app): string => json_encode(['type' => 'message', 'application' => serialize($app), 'payload' => ['event' => 'test', 'channel' => 'test', 'data' => '{}']], JSON_THROW_ON_ERROR);
    $handler->handle($envelope($old));
    expect($delivered)->toBe(1);
    $manager->apply(registrySnapshot(2, [registryApp('a', 'test-secret', 2)]));
    $handler->handle($envelope($old));
    expect($delivered)->toBe(1);
    $handler->handle($envelope($manager->findById('a')));
    expect($delivered)->toBe(2);
    $manager->apply(registrySnapshot(3, []));
    $handler->handle($envelope($old));
    expect($delivered)->toBe(2);
});

it('ignores malformed serialized applications without interrupting Redis handling', function () {
    $handler = app(ManagedIncomingMessageHandler::class);
    $empty = 'O:26:"Laravel\\Reverb\\Application":0:{}';
    $handler->handle(json_encode(['type' => 'message', 'application' => $empty], JSON_THROW_ON_ERROR));
    expect(app(ReverbManager::class)->revision())->toBe(0);
});
