<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laravel\Reverb\ServerProviderManager;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Tests\Support\WireClient;

it('adds removes and rotates across two real processes without reconnecting the other app', function (bool $scaling) {
    $dir = sys_get_temp_dir().'/reverb-wire-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $snapshotPath = $dir.'/snapshot.json';
    $writeSnapshot = function (int $revision, array $apps) use ($snapshotPath): void {
        file_put_contents($snapshotPath.'.next', json_encode(registrySnapshot($revision, $apps), JSON_THROW_ON_ERROR));
        rename($snapshotPath.'.next', $snapshotPath);
    };
    $writeSnapshot(1, [registryApp('a'), registryApp('b')]);
    file_put_contents($dir.'/unavailable', '');
    $gatewayPort = rollingPort();
    $ports = [rollingPort(), rollingPort()];
    $pids = [];
    $clients = [];
    $parentPid = getmypid();

    try {
        $redisPort = rollingPort();

        if ($scaling) {
            $binary = getenv('REVERB_TEST_REDIS_BINARY');

            if (! is_string($binary) || ! is_executable($binary)) {
                $this->markTestSkipped('Set REVERB_TEST_REDIS_BINARY to an isolated Redis or Valkey server binary.');
            }
            $redisPid = pcntl_fork();

            if ($redisPid === 0) {
                pcntl_exec($binary, ['--bind', '127.0.0.1', '--port', (string) $redisPort, '--save', '', '--appendonly', 'no', '--logfile', $dir.'/redis.log']);
                exit(1);
            }
            $pids[] = $redisPid;
            rollingWait(function () use ($redisPort): bool {
                $socket = @stream_socket_client('tcp://127.0.0.1:'.$redisPort, timeout: 0.1);

                if ($socket === false) {
                    return false;
                }
                fclose($socket);

                return true;
            });
        }
        $pid = pcntl_fork();

        if ($pid === 0) {
            Loop::set(new StreamSelectLoop);
            $server = new HttpServer(function (ServerRequestInterface $request) use ($snapshotPath, $dir): Response {
                if ($request->getHeaderLine('Authorization') !== 'Bearer registry-test') {
                    return new Response(403);
                }

                if ($request->getMethod() === 'POST') {
                    file_put_contents($dir.'/'.basename(dirname($request->getUri()->getPath())).'.boot', 'registered');

                    return new Response(204);
                }

                if ($request->getMethod() === 'PUT') {
                    if (is_file($dir.'/drop-reports')) {
                        return new Response(503);
                    }
                    file_put_contents($dir.'/'.basename($request->getUri()->getPath()).'.report', (string) $request->getBody());

                    return new Response(204);
                }

                file_put_contents($dir.'/snapshot-requested', 'requested');

                if (is_file($dir.'/unavailable')) {
                    return new Response(503);
                }

                $snapshot = file_get_contents($snapshotPath);

                if ($snapshot === '{invalid') {
                    file_put_contents($dir.'/invalid-served', '');
                }

                return new Response(200, ['Content-Type' => 'application/json'], $snapshot);
            });
            $server->listen(new SocketServer('127.0.0.1:'.$gatewayPort));
            Loop::run();
            exit(0);
        }
        $pids[] = $pid;
        foreach ($ports as $i => $port) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                Loop::set(new StreamSelectLoop);
                config()->set([
                    'registry.registry_url' => 'http://127.0.0.1:'.$gatewayPort.'/registry',
                    'registry.registry_token' => 'registry-test',
                    'registry.notification_token' => 'notify-test',
                    'registry.process_id' => 'process-'.$i,
                    'registry.heartbeat_interval' => 0.02,
                    'registry.reconcile_interval' => 0.05,
                    'cache.default' => 'array',
                    'reverb.servers.reverb.port' => $port,
                    'reverb.servers.reverb.scaling.enabled' => $scaling,
                    'reverb.servers.reverb.scaling.server.host' => '127.0.0.1',
                    'reverb.servers.reverb.scaling.server.port' => $redisPort,
                    'reverb.servers.reverb.scaling.channel' => 'isolated-rolling-test',
                ]);
                // The application booted before the child-specific Redis configuration was known.
                app(ServerProviderManager::class)->forgetDrivers();
                app(ServerProviderManager::class)->register();
                Artisan::call('orbit:reverb:start', ['--host' => '127.0.0.1']);
                exit(0);
            }
            $pids[] = $pid;
        }
        $waitApplied = function (int $revision) use ($dir): void {
            rollingWait(function () use ($revision, $dir): bool {
                foreach ([0, 1] as $i) {
                    $path = $dir.'/process-'.$i.'.report';
                    $report = is_file($path) ? json_decode(file_get_contents($path), true) : [];

                    if (($report['revision'] ?? 0) !== $revision || ($report['state'] ?? '') !== 'serving') {
                        return false;
                    }
                }

                return true;
            });
        };
        rollingWait(fn (): bool => is_file($dir.'/process-0.boot') && is_file($dir.'/process-1.boot') && is_file($dir.'/snapshot-requested'));
        foreach ($ports as $port) {
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, timeout: 0.1);
            expect($socket)->toBeFalse();
        }
        file_put_contents($snapshotPath, '{invalid');
        unlink($dir.'/unavailable');
        rollingWait(fn (): bool => is_file($dir.'/invalid-served'));
        foreach ($ports as $port) {
            expect(@stream_socket_client('tcp://127.0.0.1:'.$port, timeout: 0.1))->toBeFalse();
        }
        $writeSnapshot(1, [registryApp('a'), registryApp('b')]);
        $waitApplied(1);
        $boots = array_map(fn (int $i): string => json_decode(file_get_contents($dir.'/process-'.$i.'.report'), true)['boot_id'], [0, 1]);
        foreach ($ports as $i => $port) {
            $clients[$i]['a'] = new WireClient($port, 'a-key');
            $clients[$i]['b'] = new WireClient($port, 'b-key');
            rollingSubscribe($clients[$i]['b'], 'private-stable', 'b-key', 'test-secret');
            rollingSubscribe($clients[$i]['b'], 'presence-stable-'.$i, 'b-key', 'test-secret', ['user_id' => 'user-'.$i, 'user_info' => ['name' => 'User']]);
        }
        file_put_contents($dir.'/unavailable', '');
        file_put_contents($dir.'/drop-reports', '');
        $writeSnapshot(2, [registryApp('a'), registryApp('b'), registryApp('c')]);
        foreach ($ports as $port) {
            expect(rollingHttp($port, '/internal/orbit/registry-changed', 'POST', ['epoch' => 'test-registry', 'revision' => 2]))->toBe(202);
            expect(rollingHttp($port, '/internal/orbit/registry-changed', 'POST', [], 'wrong'))->toBe(403);
        }
        usleep(100000);
        foreach ($ports as $i => $port) {
            expect(json_decode(file_get_contents($dir.'/process-'.$i.'.report'), true)['revision'])->toBe(1);
            $clients[$i]['b']->send(['event' => 'pusher:ping', 'data' => []]);
            expect($clients[$i]['b']->event()['event'])->toBe('pusher:pong');
        }
        unlink($dir.'/unavailable');
        foreach ($ports as $i => $port) {
            rollingWait(fn (): bool => rollingPublish($port, 'c', 'test-secret', 'private-test', 'applied-without-ack') === 200);
            expect(json_decode(file_get_contents($dir.'/process-'.$i.'.report'), true)['revision'])->toBe(1);
        }
        unlink($dir.'/drop-reports');
        $waitApplied(2);
        foreach ($ports as $i => $port) {
            $clients[$i]['c'] = new WireClient($port, 'c-key');
            $clients[$i]['subscribed-c'] = new WireClient($port, 'c-key');
            rollingSubscribe($clients[$i]['subscribed-c'], 'private-test', 'c-key', 'test-secret');
            rollingSubscribe($clients[$i]['subscribed-c'], 'presence-test-'.$i, 'c-key', 'test-secret', ['user_id' => 'member-'.$i, 'user_info' => ['name' => 'Member']]);
        }
        rollingAssertDelivery($ports, $clients, $scaling, 'before-remove');
        $writeSnapshot(3, [registryApp('a'), registryApp('b')]);
        // No notification: reconciliation must repair a lost webhook on both processes.
        $waitApplied(3);
        foreach ($clients as $set) {
            expect($set['c']->closeCode())->toBe(4001)
                ->and($set['subscribed-c']->closeCode())->toBe(4001);
        }
        rollingAssertDelivery($ports, $clients, $scaling, 'after-remove');
        foreach ($ports as $port) {
            expect(rollingPublish($port, 'c', 'test-secret', 'private-test', 'removed'))->toBe(404);
        }
        $writeSnapshot(4, [registryApp('a', 'rotated-secret', 2), registryApp('b')]);
        $waitApplied(4);
        foreach ($clients as $set) {
            expect($set['a']->closeCode())->toBe(4201);
            $set['b']->send(['event' => 'pusher:ping', 'data' => []]);
            expect($set['b']->event()['event'])->toBe('pusher:pong');
        }
        rollingAssertDelivery($ports, $clients, $scaling, 'after-rotation');
        foreach ($ports as $i => $port) {
            expect(rollingPublish($port, 'a', 'test-secret', 'private-test', 'stale'))->toBe(401);
            expect(rollingPublish($port, 'a', 'rotated-secret', 'private-test', 'current'))->toBe(200);
            $clients[$i]['new-a'] = new WireClient($port, 'a-key');
            rollingSubscribe($clients[$i]['new-a'], 'private-test', 'a-key', 'rotated-secret');
            $report = json_decode(file_get_contents($dir.'/process-'.$i.'.report'), true);
            expect($report['boot_id'])->toBe($boots[$i]);
        }
    } catch (Throwable $exception) {
        if (getmypid() !== $parentPid) {
            file_put_contents($dir.'/child-error-'.getmypid(), (string) $exception);
            exit(1);
        }

        $errors = array_map(file_get_contents(...), glob($dir.'/child-error-*'));

        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors), previous: $exception);
        }

        throw $exception;
    } finally {
        foreach ($clients as $set) {
            foreach ($set as $client) {
                $client->close();
            }
        }
        foreach ($pids as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        foreach (glob($dir.'/*') as $path) {
            unlink($path);
        }
        rmdir($dir);
    }
})->with(['local delivery' => false, 'Redis scale-out delivery' => true])->skip(! function_exists('pcntl_fork'), 'Requires process isolation with pcntl.');
