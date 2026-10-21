<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Tests\Support\WireClient;

it('exits with failure on a registry history change and cold starts with the restored snapshot', function (string $change): void {
    $dir = sys_get_temp_dir().'/reverb-reset-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    $snapshotPath = $dir.'/snapshot.json';
    file_put_contents($snapshotPath, json_encode(registrySnapshot(2, [registryApp('old')]), JSON_THROW_ON_ERROR));
    $gatewayPort = rollingPort();
    $serverPort = rollingPort();
    $pids = [];
    $clients = [];
    $status = null;

    try {
        $gateway = pcntl_fork();
        if ($gateway === 0) {
            Loop::set(new StreamSelectLoop);
            $server = new HttpServer(function (ServerRequestInterface $request) use ($snapshotPath): Response {
                return $request->getMethod() === 'GET'
                    ? new Response(200, ['Content-Type' => 'application/json'], file_get_contents($snapshotPath))
                    : new Response(204);
            });
            $server->listen(new SocketServer('127.0.0.1:'.$gatewayPort));
            Loop::run();
            exit(0);
        }
        $pids[] = $gateway;
        rollingWait(function () use ($gatewayPort): bool {
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$gatewayPort, timeout: 0.1);
            if ($socket === false) {
                return false;
            }
            fclose($socket);

            return true;
        });
        $start = function () use ($gatewayPort, $serverPort, $dir): int {
            $pid = pcntl_fork();
            if ($pid === 0) {
                Loop::set(new StreamSelectLoop);
                config()->set([
                    'registry.registry_url' => 'http://127.0.0.1:'.$gatewayPort.'/registry',
                    'registry.registry_token' => 'registry-test',
                    'registry.notification_token' => 'notification-test',
                    'registry.process_id' => 'restore-test',
                    'registry.reconcile_interval' => 0.02,
                    'reverb.servers.reverb.port' => $serverPort,
                    'cache.default' => 'array',
                ]);
                try {
                    exit(Artisan::call('orbit:reverb:start', ['--host' => '127.0.0.1']));
                } catch (Throwable $exception) {
                    file_put_contents($dir.'/failure', $exception->getMessage());
                    exit(1);
                }
            }

            return $pid;
        };
        $waitListening = function () use ($serverPort): void {
            rollingWait(function () use ($serverPort): bool {
                $socket = @stream_socket_client('tcp://127.0.0.1:'.$serverPort, timeout: 0.1);
                if ($socket === false) {
                    return false;
                }
                fclose($socket);

                return true;
            });
        };
        $pid = $start();
        $pids[] = $pid;
        $waitListening();
        $clients[] = new WireClient($serverPort, 'old-key');

        $restored = registrySnapshot(match ($change) {
            'lower revision' => 1, 'epoch' => 3, default => 2
        }, [registryApp('restored')]);
        if ($change === 'epoch') {
            $restored['epoch'] = 'restored-history';
        }
        file_put_contents($snapshotPath.'.next', json_encode($restored, JSON_THROW_ON_ERROR));
        rename($snapshotPath.'.next', $snapshotPath);
        rollingWait(function () use ($pid, &$status): bool {
            return pcntl_waitpid($pid, $status, WNOHANG) === $pid;
        });
        $pids = array_values(array_diff($pids, [$pid]));
        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(1)
            ->and(file_get_contents($dir.'/failure'))->toBe('Registry history changed; restart required.');
        expect(@stream_socket_client('tcp://127.0.0.1:'.$serverPort, timeout: 0.1))->toBeFalse();

        $pids[] = $start();
        $waitListening();
        $clients[] = new WireClient($serverPort, 'restored-key');
        expect(rollingPublish($serverPort, 'old', 'test-secret', 'private-test', 'removed'))->toBe(404)
            ->and(rollingPublish($serverPort, 'restored', 'test-secret', 'private-test', 'current'))->toBe(200);
    } finally {
        foreach ($clients as $client) {
            $client->close();
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
})->with(['epoch', 'same revision', 'lower revision'])->skip(! function_exists('pcntl_fork'), 'Requires process isolation with pcntl.');
