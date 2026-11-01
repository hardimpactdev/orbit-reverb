<?php

declare(strict_types=1);

use App\Reverb\Connections;
use App\Reverb\Control\RefreshCoordinator;
use App\Reverb\Control\RegistryApplyController;
use App\Reverb\Protocol\ManagedConnection;
use App\Reverb\Protocol\ManagedFactory;
use App\Reverb\ReverbManager;
use GuzzleHttp\Psr7\Request;
use Laravel\Reverb\Servers\Reverb\Connection as Transport;
use Laravel\Reverb\Servers\Reverb\Http\Connection as HttpConnection;
use Laravel\Reverb\Servers\Reverb\Http\Response as ReverbResponse;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

function registryApplyResponse(ReverbResponse|PromiseInterface $pending): ReverbResponse
{
    if ($pending instanceof ReverbResponse) {
        return $pending;
    }
    $result = $error = null;
    $timer = Loop::addTimer(2, static function (): void {
        Loop::stop();
    });
    $pending->then(function (ReverbResponse $response) use (&$result): void {
        $result = $response;
        Loop::stop();
    }, function (Throwable $failure) use (&$error): void {
        $error = $failure;
        Loop::stop();
    });
    Loop::run();
    Loop::cancelTimer($timer);
    if ($error !== null) {
        throw $error;
    }
    if (! $result instanceof ReverbResponse) {
        throw new RuntimeException('The disposable apply request did not complete.');
    }

    return $result;
}

function withRegistryApply(Closure $test): void
{
    $previous = Loop::get();
    Loop::set(new StreamSelectLoop);
    $state = (object) ['snapshot' => registrySnapshot(1, [registryApp('a'), registryApp('b')]), 'unavailable' => false, 'failReadAt' => null, 'reads' => 0, 'reportStatus' => 204, 'reports' => 0, 'failReportRevision' => null];
    $server = new HttpServer(static function (ServerRequestInterface $request) use ($state): Response {
        if ($request->getHeaderLine('Authorization') !== 'Bearer registry-fixture') {
            return new Response(403);
        }
        if ($request->getMethod() !== 'GET') {
            if ($request->getMethod() === 'PUT') {
                $state->reports++;

                $revision = json_decode((string) $request->getBody(), true)['revision'];
                if ($revision === $state->failReportRevision) {
                    $state->failReportRevision = null;

                    return new Response(409);
                }

                return new Response($state->reportStatus);
            }

            return new Response(204);
        }
        $state->reads++;

        return $state->unavailable || $state->reads === $state->failReadAt ? new Response(503, [], 'private-provider-response')
            : new Response(200, ['Content-Type' => 'application/json'], json_encode($state->snapshot, JSON_THROW_ON_ERROR));
    });
    $socket = new SocketServer('127.0.0.1:0');
    $server->listen($socket);
    config()->set([
        'registry.registry_url' => str_replace('tcp://', 'http://', $socket->getAddress()).'/registry',
        'registry.registry_token' => 'registry-fixture', 'registry.notification_token' => null,
        'registry.process_id' => 'process-fixture', 'registry.reconcile_interval' => 300, 'registry.heartbeat_interval' => 300,
    ]);
    $refresh = new RefreshCoordinator(app(ReverbManager::class), app(Connections::class));
    $timer = Loop::addTimer(2, static function (): void {
        Loop::stop();
    });
    try {
        $started = false;
        $refresh->start(function () use (&$started): void {
            $started = true;
            Loop::stop();
        });
        Loop::run();
        expect($started)->toBeTrue();
        Loop::cancelTimer($timer);
        app()->instance(RefreshCoordinator::class, $refresh);
        $controller = new RegistryApplyController($refresh, app(ReverbManager::class));
        $request = static fn (array $intent, string $token = 'registry-fixture'): Request => new Request('POST', '/internal/orbit/registry/apply', ['Authorization' => 'Bearer '.$token], json_encode($intent, JSON_THROW_ON_ERROR));
        $intent = ['process_id' => 'process-fixture', 'epoch' => 'test-registry', 'revision' => 2, 'kind' => 'sync'];
        $test($controller, $request, $intent, $state);
    } finally {
        $socket->close();
        Loop::set($previous);
    }
}

it('authenticates before reading or refreshing the registry', function (): void {
    withRegistryApply(function ($controller, $request, $intent, $state): void {
        $before = $state->reads;
        foreach (['', 'wrong'] as $token) {
            $response = $controller($request($intent, $token));
            expect($response->getStatusCode())->toBe(403)->and($state->reads)->toBe($before);
        }
        config()->set('registry.registry_token', null);
        expect($controller($request($intent))->getStatusCode())->toBe(403)->and($state->reads)->toBe($before);
    });
});

it('refuses invalid or foreign intent before refreshing', function (): void {
    withRegistryApply(function ($controller, $request, $intent, $state): void {
        foreach ([['revision' => 0], ['revision' => '2'], ['kind' => 'other'], ['secret' => 'private-fixture'], ['kind' => 'rotate', 'app_id' => 'a'], ['kind' => 'destroy'], ['epoch' => ''], ['kind' => 'sync', 'app_id' => 'a']] as $change) {
            expect($controller($request(array_replace($intent, $change)))->getStatusCode())->toBe(422);
        }
        expect($controller($request(array_replace($intent, ['process_id' => 'foreign'])))->getStatusCode())->toBe(409)
            ->and($controller(new Request('POST', '/internal/orbit/registry/apply', ['Authorization' => 'Bearer registry-fixture'], str_repeat('x', 4097)))->getStatusCode())->toBe(413)
            ->and($controller($request($intent)->withHeader('Upgrade', 'websocket'))->getStatusCode())->toBe(400)
            ->and($state->reads)->toBe(1);
    });
});

it('applies one refresh and returns only live public confirmation', function (string $kind): void {
    withRegistryApply(function ($controller, $request, $intent, $state) use ($kind): void {
        $apps = [registryApp('a'), registryApp('b')];
        $change = ['kind' => $kind];
        if ($kind === 'create') {
            $apps[] = registryApp('c');
            $change['app_id'] = 'c';
        } elseif ($kind === 'destroy') {
            $apps = [registryApp('b')];
            $change['app_id'] = 'a';
        } elseif ($kind === 'rotate') {
            $apps[0] = registryApp('a', 'rotated-private-fixture', 2);
            $change += ['app_id' => 'a', 'credential_generation' => 2];
        }
        $state->snapshot = registrySnapshot(2, $apps);
        $response = registryApplyResponse($controller($request(array_replace($intent, $change))));
        $result = json_decode($response->getContent(), true);
        expect($response->getStatusCode())->toBe(200)->and($result)->toMatchArray(['outcome' => 'succeeded', 'data' => ['backend' => 'applied']])
            ->and($result['runtime'])->toMatchArray(['process_id' => 'process-fixture', 'epoch' => 'test-registry', 'revision' => 2, 'state' => 'serving'])
            ->and($result['runtime']['boot_id'])->toBeUuid()->and($state->reads)->toBe(2)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->getContent())->not->toContain('test-secret', 'rotated-private-fixture', 'registry-fixture', 'a-key');
    });
})->with(['sync', 'create', 'destroy', 'rotate']);

it('reports an unavailable fetch without waiting for background retries and accepts later recovery', function (): void {
    withRegistryApply(function ($controller, $request, $intent, $state): void {
        $state->unavailable = true;
        $response = registryApplyResponse($controller($request($intent)));
        expect($response->getStatusCode())->toBe(409)->and(json_decode($response->getContent(), true)['error_code'])->toBe('websocket.registry_pending')
            ->and($response->getContent())->not->toContain('private-provider-response')->and($state->reads)->toBe(2);
        $state->unavailable = false;
        $state->snapshot = registrySnapshot(2, [registryApp('a'), registryApp('b')]);
        expect(registryApplyResponse($controller($request($intent)))->getStatusCode())->toBe(200)->and($state->reads)->toBe(3);
    });
});

it('keeps the legacy preserved-change and supersession rules at later revisions', function (string $kind, ?int $generation, array $apps, int $status): void {
    withRegistryApply(function ($controller, $request, $intent, $state) use ($kind, $generation, $apps, $status): void {
        $state->snapshot = registrySnapshot(3, $apps);
        $response = registryApplyResponse($controller($request(array_replace($intent, ['kind' => $kind, 'app_id' => 'a', 'credential_generation' => $generation]))));
        expect($response->getStatusCode())->toBe($status);
        if ($status === 409) {
            expect(json_decode($response->getContent(), true)['error_code'])->toBe('websocket.registry_superseded');
        }
    });
})->with([
    'create survives rotation' => ['create', null, [registryApp('a', 'current', 3)], 200],
    'removed create' => ['create', null, [registryApp('b')], 409],
    'newer rotation' => ['rotate', 2, [registryApp('a', 'current', 3)], 409],
    'preserved rotation' => ['rotate', 2, [registryApp('a', 'current', 2), registryApp('b')], 200],
    'preserved removal' => ['destroy', null, [registryApp('b')], 200],
    'conflicting removal' => ['destroy', null, [registryApp('a')], 409],
]);

it('does not confirm another epoch or an unapplied revision', function (array $change): void {
    withRegistryApply(function ($controller, $request, $intent, $state) use ($change): void {
        $response = registryApplyResponse($controller($request(array_replace($intent, $change))));
        expect($response->getStatusCode())->toBe(409)->and(json_decode($response->getContent(), true)['error_code'])->toBe('websocket.registry_pending');
    });
})->with([[['epoch' => 'foreign-epoch']], [['revision' => 4]]]);

it('completes after revoked sockets leave and preserves unrelated sockets', function (): void {
    $manager = app(ReverbManager::class);
    $manager->apply(registrySnapshot(1, [registryApp('a'), registryApp('b')]));
    $connections = app(Connections::class);
    $streams = [];
    foreach (['a', 'b'] as $id) {
        $raw = Mockery::mock(ConnectionInterface::class);
        $streams[] = $raw->stream = fopen('php://temp', 'r+');
        $connections->add($clients[$id] = new ManagedConnection(new Transport(new HttpConnection($raw)), $manager->findById($id), null));
    }
    $clients['a']->markRevoked(4001);
    $settled = false;
    $connections->whenSettled(function () use (&$settled): void {
        $settled = true;
    });
    expect($settled)->toBeFalse();
    $connections->remove($clients['a']);
    expect($settled)->toBeTrue()->and($connections->counts())->toBe(['b' => 1]);
    foreach ($streams as $stream) {
        fclose($stream);
    }
});

it('serves the apply promise through the unmodified Reverb HTTP router', function (): void {
    withRegistryApply(function ($controller, $request, $intent, $state): void {
        $state->snapshot = registrySnapshot(2, [registryApp('a'), registryApp('b')]);
        $raw = Mockery::mock(ConnectionInterface::class);
        $raw->stream = fopen('php://temp', 'r+');
        $sent = false;
        $raw->shouldReceive('write')->once()->withArgs(function (string $message) use (&$sent): bool {
            expect($message)->toContain('200 OK', '"backend":"applied"', '"process_id":"process-fixture"')
                ->not->toContain('registry-fixture', 'test-secret');
            $sent = true;
            Loop::stop();

            return true;
        });
        $raw->shouldReceive('end')->once();
        $timer = Loop::addTimer(2, static function (): void {
            Loop::stop();
        });
        try {
            ManagedFactory::makePusherRouter('')->dispatch($request($intent), new HttpConnection($raw));
            Loop::run();
            expect($sent)->toBeTrue()->and($state->reads)->toBe(2);
        } finally {
            Loop::cancelTimer($timer);
            fclose($raw->stream);
        }
    });
});

it('settles a failed refresh independently of the next overlapping request', function (): void {
    withRegistryApply(function ($controller, $request, $intent, $state): void {
        $state->snapshot = registrySnapshot(2, [registryApp('a'), registryApp('b')]);
        $state->failReadAt = 2;
        $first = $controller($request($intent));
        $second = $controller($request($intent));
        $response = registryApplyResponse(\React\Promise\all([$first, $second])->then(static fn (array $responses): ReverbResponse => new ReverbResponse([
            'statuses' => array_map(static fn (ReverbResponse $response): int => $response->getStatusCode(), $responses),
        ])));
        expect(json_decode($response->getContent(), true)['statuses'])->toBe([409, 200])->and($state->reads)->toBe(3);
    });
});

it('refuses native confirmation when the Gateway rejects this superseded boot report', function (): void {
    withRegistryApply(function ($controller, $request, $intent, $state): void {
        $state->snapshot = registrySnapshot(2, [registryApp('a'), registryApp('b')]);
        $state->reportStatus = 409;

        $response = registryApplyResponse($controller($request($intent)));

        expect($response->getStatusCode())->toBe(409)
            ->and(json_decode($response->getContent(), true)['error_code'])->toBe('websocket.registry_pending');
    });
});

it('keeps a failed boot acknowledgement separate from a later overlapping success', function (): void {
    withRegistryApply(function ($controller, $request, $intent, $state): void {
        $state->snapshot = registrySnapshot(2, [registryApp('a'), registryApp('b')]);
        $state->failReportRevision = 2;
        $first = $controller($request($intent));
        $second = $controller($request($intent));
        $statuses = [];
        foreach ([$first, $second] as $pending) {
            $pending->then(function (ReverbResponse $response) use (&$statuses): void {
                $statuses[] = $response->getStatusCode();
                if (count($statuses) === 2) {
                    Loop::stop();
                }
            });
        }
        $deadline = Loop::addTimer(2, static function (): void {
            Loop::stop();
        });
        Loop::run();
        Loop::cancelTimer($deadline);

        expect($statuses)->toBe([409, 200]);
    });
});

it('confirms the acknowledged version after its actual revoked socket closes', function (): void {
    withRegistryApply(function ($controller, $request, $intent, $state): void {
        $connections = app(Connections::class);
        $raw = Mockery::mock(ConnectionInterface::class);
        $raw->stream = fopen('php://temp', 'r+');
        $client = new ManagedConnection(new Transport(new HttpConnection($raw)), app(ReverbManager::class)->findById('a'), null);
        $connections->add($client);
        $client->markRevoked(4001);
        $state->snapshot = registrySnapshot(2, [registryApp('a'), registryApp('b')]);
        $browser = Mockery::mock(Browser::class);
        $browser->shouldReceive('get')->once()->andReturn(\React\Promise\resolve(new Response(200, [], json_encode($state->snapshot, JSON_THROW_ON_ERROR))));
        $browser->shouldReceive('put')->once()->andReturn(\React\Promise\resolve(new Response(204)));
        (new ReflectionProperty(RefreshCoordinator::class, 'client'))->setValue(app(RefreshCoordinator::class), $browser);
        $result = null;
        $pending = $controller($request($intent));
        $pending->then(function (ReverbResponse $response) use (&$result): void {
            $result = $response;
        });
        // The ordinary report is asynchronous; finish it, then release the held close event.
        $deadline = Loop::addTimer(0.05, static function (): void {
            Loop::stop();
        });
        Loop::run();
        Loop::cancelTimer($deadline);
        expect($result)->toBeNull();

        $connections->remove($client);

        expect($result)->toBeInstanceOf(ReverbResponse::class)
            ->and($result->getStatusCode())->toBe(200)
            ->and(json_decode($result->getContent(), true)['runtime'])->toMatchArray(['revision' => 2, 'state' => 'serving']);
        fclose($raw->stream);
    });
});
