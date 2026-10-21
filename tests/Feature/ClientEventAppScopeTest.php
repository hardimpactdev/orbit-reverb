<?php

declare(strict_types=1);

use App\Reverb\ReverbManager;
use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Jobs\PingInactiveConnections;
use Laravel\Reverb\Jobs\PruneStaleConnections;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\Server;
use Laravel\Reverb\Servers\Reverb\Factory;

beforeEach(function (): void {
    app(ReverbManager::class)->apply(registrySnapshot(1, [registryApp('gateway-id'), registryApp('hauzer-id')]));
});

it('checks a client event in its own app after periodic housekeeping', function (string $job) {
    Factory::makePusherRouter('');
    $apps = app(ApplicationProvider::class);
    $gateway = $apps->findById('gateway-id');
    $channels = app(ChannelManager::class);
    $server = app(Server::class);
    $agent = scope_connection($gateway, '1.17', $agentFrames);
    $viewer = scope_connection($gateway, '2.17', $viewerFrames);
    scope_subscribe($server, $agent, 'presence-node.17', 'agent.17');
    scope_subscribe($server, $viewer, 'presence-node.17', 'viewer.17');
    expect($agentFrames[0]['event'])->toBe('pusher_internal:subscription_succeeded');
    expect($channels->for($gateway)->find('presence-node.17')->find($agent))->not->toBeNull();
    $agentFrames = $viewerFrames = [];

    // The real jobs run every 60 seconds and leave the singleton manager on the last app.
    (new $job)->handle($channels);
    expect($channels->app()->id())->toBe('hauzer-id');
    $server->message($agent, json_encode([
        'event' => 'client-heartbeat', 'channel' => 'presence-node.17', 'data' => ['sequence' => 1],
    ], JSON_THROW_ON_ERROR));

    expect($agentFrames)->toBe([])
        ->and($viewerFrames)->toBe([[
            'event' => 'client-heartbeat', 'channel' => 'presence-node.17',
            'data' => ['sequence' => 1], 'user_id' => 'agent.17',
        ]]);
})->with([PruneStaleConnections::class, PingInactiveConnections::class]);

it('keeps same-named presence channels and sender identities separate across apps', function () {
    Factory::makePusherRouter('');
    $apps = app(ApplicationProvider::class);
    $server = app(Server::class);
    $gateway = scope_connection($apps->findById('gateway-id'), '1.17', $gatewayFrames);
    $gatewayViewer = scope_connection($apps->findById('gateway-id'), '2.17', $gatewayViewerFrames);
    $hauzer = scope_connection($apps->findById('hauzer-id'), '3.17', $hauzerFrames);
    $hauzerViewer = scope_connection($apps->findById('hauzer-id'), '4.17', $hauzerViewerFrames);
    foreach ([$gateway, $gatewayViewer, $hauzer, $hauzerViewer] as $connection) {
        scope_subscribe($server, $connection, 'presence-node.17', $connection->id());
    }
    $gatewayFrames = $gatewayViewerFrames = $hauzerFrames = $hauzerViewerFrames = [];
    foreach ([$gateway, $hauzer, $gateway] as $connection) {
        $server->message($connection, json_encode([
            'event' => 'client-heartbeat', 'channel' => 'presence-node.17',
            'data' => [], 'user_id' => 'forged',
        ], JSON_THROW_ON_ERROR));
    }
    expect($gatewayFrames)->toBe([])->and($hauzerFrames)->toBe([])
        ->and(array_column($gatewayViewerFrames, 'user_id'))->toBe([$gateway->id(), $gateway->id()])
        ->and(array_column($hauzerViewerFrames, 'user_id'))->toBe([$hauzer->id()]);
});

it('still refuses invalid presence and private signatures and client events from nonmembers', function () {
    Factory::makePusherRouter('');
    $apps = app(ApplicationProvider::class);
    $server = app(Server::class);
    $member = scope_connection($apps->findById('gateway-id'), '1.17', $memberFrames);
    $outsider = scope_connection($apps->findById('gateway-id'), '2.17', $outsiderFrames);
    scope_subscribe($server, $member, 'presence-node.17', 'agent.17');
    $server->message($outsider, json_encode([
        'event' => 'client-heartbeat', 'channel' => 'presence-node.17', 'data' => [],
    ], JSON_THROW_ON_ERROR));
    expect(json_decode($outsiderFrames[0]['data'], true))->toBe([
        'code' => 4009, 'message' => 'The client is not a member of the specified channel.',
    ]);
    foreach (['presence-node.17', 'private-orbit'] as $channel) {
        $outsiderFrames = [];
        $server->message($outsider, json_encode([
            'event' => 'pusher:subscribe', 'data' => [
                'channel' => $channel, 'auth' => 'gateway-key:invalid',
                'channel_data' => '{"user_id":"agent.17"}',
            ],
        ], JSON_THROW_ON_ERROR));
        expect(json_decode($outsiderFrames[0]['data'], true)['message'])->toBe('Connection is unauthorized');
    }
});

/** @param array<int, array<string, mixed>>|null $frames */
function scope_connection(Application $application, string $socketId, ?array &$frames): Connection
{
    $frames = [];
    $transport = Mockery::mock(WebSocketConnection::class);
    $transport->shouldReceive('id')->andReturn($socketId);
    $transport->shouldReceive('send')->andReturnUsing(function (string $message) use (&$frames): void {
        $frames[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
    });

    return new Laravel\Reverb\Connection($transport, $application, null);
}

function scope_subscribe(Server $server, Connection $connection, string $channel, string $member): void
{
    $data = json_encode(['user_id' => $member, 'user_info' => ['kind' => 'test']], JSON_THROW_ON_ERROR);
    $auth = $connection->app()->key().':'.hash_hmac('sha256', $connection->id().':'.$channel.':'.$data, $connection->app()->secret());
    $server->message($connection, json_encode([
        'event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $auth, 'channel_data' => $data],
    ], JSON_THROW_ON_ERROR));
}
