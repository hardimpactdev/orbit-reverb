<?php

declare(strict_types=1);

namespace App\Reverb\Console\Commands;

use App\Reverb\Connections;
use App\Reverb\Control\RefreshCoordinator;
use App\Reverb\Protocol\ManagedFactory;
use Laravel\Reverb\Servers\Reverb\Console\Commands\StartServer;
use React\EventLoop\Loop;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'orbit:reverb:start')]
final class ReverbManagerCommand extends StartServer
{
    protected $signature = 'orbit:reverb:start {--host=} {--port=} {--path=} {--hostname=}';

    protected $description = 'Start Reverb with live application management.';

    public function handle(): void
    {
        $loop = Loop::get();
        $config = config('reverb.servers.reverb');
        $host = $this->option('host') ?? $config['host'];
        $port = $this->positiveInteger($this->option('port') ?? $config['port'], 65535);
        $path = $this->option('path') ?? ($config['path'] ?? '');
        $hostname = $this->option('hostname') ?? ($config['hostname'] ?? null);
        $maxRequest = $this->positiveInteger($config['max_request_size'] ?? 10000);
        $pulseInterval = $this->positiveInteger($config['pulse_ingest_interval'] ?? 15);
        $telescopeInterval = $this->positiveInteger($config['telescope_ingest_interval'] ?? 15);

        if (! is_string($host) || $host === '' || ! is_string($path) || ($hostname !== null && ! is_string($hostname)) || ! is_array($config['options'] ?? [])) {
            throw new RuntimeException('Reverb server configuration is invalid.');
        }
        $this->laravel->make(RefreshCoordinator::class)->start(function () use ($loop, $config, $host, $port, $path, $hostname, $maxRequest, $pulseInterval, $telescopeInterval): void {
            $server = ManagedFactory::make(
                $host,
                (string) $port,
                $path,
                $hostname,
                $maxRequest,
                $config['options'] ?? [],
                loop: $loop,
            );
            $this->ensureHorizontalScalability($loop);
            $this->ensureStaleConnectionsAreCleaned($loop);
            $this->ensureRestartCommandIsRespected($server, $loop, $host, (string) $port);
            $this->ensurePulseEventsAreCollected($loop, $pulseInterval);
            $this->ensureTelescopeEntriesAreCollected($loop, $telescopeInterval);
            $this->components->info('Reverb registry loaded; server accepting connections.');
        });
        $loop->run();
        $this->laravel->make(RefreshCoordinator::class)->assertCurrentHistory();
    }

    private function positiveInteger(mixed $value, int $maximum = 2147483647): int
    {
        if ((! is_int($value) && ! is_string($value)) || ($parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]])) === false) {
            throw new RuntimeException('Reverb server configuration is invalid.');
        }

        return $parsed;
    }

    protected function gracefullyDisconnect(): void
    {
        $this->laravel->make(Connections::class)->shutdown();
    }
}
