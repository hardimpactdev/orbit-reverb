<?php

declare(strict_types=1);

namespace HardImpact\ReverbManager\Control;

use Closure;
use HardImpact\ReverbManager\Connections;
use HardImpact\ReverbManager\Measurements;
use HardImpact\ReverbManager\ReverbManager;
use Illuminate\Support\Str;
use Laravel\Reverb\Application;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use RuntimeException;
use Throwable;

final class RefreshCoordinator
{
    private ?TimerInterface $retryTimer = null;

    private bool $fetching = false;

    private bool $pending = false;

    private bool $reporting = false;

    private bool $serving = false;

    private bool $registered = false;

    private int $reportSequence = 0;

    private ?string $error = null;

    private ?Closure $onReady = null;

    private readonly string $bootId;

    private Browser $client;

    public function __construct(private readonly ReverbManager $manager, private readonly Connections $connections)
    {
        $this->bootId = (string) Str::uuid();
        $this->client = (new Browser)->withTimeout((float) config('reverb-manager.request_timeout'))
            ->withFollowRedirects(false)->withRejectErrorResponse(false)->withResponseBuffer(16 * 1024 * 1024);
    }

    public function start(Closure $onReady): void
    {
        foreach (['registry_url', 'registry_token', 'notification_token', 'process_id'] as $key) {
            if (! is_string(config('reverb-manager.'.$key)) || config('reverb-manager.'.$key) === '') {
                throw new RuntimeException('Reverb registry configuration is incomplete.');
            }
        }
        foreach (['request_timeout', 'reconcile_interval', 'heartbeat_interval'] as $key) {
            $value = config('reverb-manager.'.$key);

            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
                throw new RuntimeException('Reverb registry timing configuration is invalid.');
            }
        }
        $url = (string) config('reverb-manager.registry_url');

        if (! str_starts_with($url, 'https://') && ! (app()->environment('testing') && str_starts_with($url, 'http://127.0.0.1:'))) {
            throw new RuntimeException('Reverb registry requires HTTPS.');
        }
        $this->onReady = $onReady;
        Loop::addPeriodicTimer((float) config('reverb-manager.reconcile_interval'), $this->refresh(...));
        Loop::addPeriodicTimer((float) config('reverb-manager.heartbeat_interval'), $this->report(...));
        $this->refresh();
    }

    public function refresh(): void
    {
        if ($this->fetching) {
            $this->pending = true;

            return;
        }

        if ($this->retryTimer !== null) {
            Loop::cancelTimer($this->retryTimer);
            $this->retryTimer = null;
        }
        $this->fetching = true;

        if (! $this->registered) {
            $this->client->post($this->processUrl().'/boots', $this->headers() + ['Content-Type' => 'application/json'], json_encode(['boot_id' => $this->bootId], JSON_THROW_ON_ERROR))
                ->then(function (ResponseInterface $response): void {
                    $this->registered = $response->getStatusCode() === 204;

                    if (! $this->registered) {
                        $this->error = 'registry.registration_failed';
                    }
                }, function (): void {
                    $this->error = 'registry.unreachable';
                })->finally(function (): void {
                    $this->fetching = false;

                    if ($this->registered) {
                        $this->refresh();
                    } else {
                        $this->retry();
                    }
                });

            return;
        }
        $headers = $this->headers();

        if ($this->manager->epoch() !== null) {
            $headers['If-None-Match'] = '"'.$this->manager->epoch().':'.$this->manager->revision().'"';
        }
        $this->client->get((string) config('reverb-manager.registry_url'), $headers)->then(
            function (ResponseInterface $response): void {
                try {
                    if ($response->getStatusCode() === 200) {
                        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

                        if (! is_array($data)) {
                            throw new RuntimeException;
                        }
                        $this->manager->apply($data);
                    } elseif ($response->getStatusCode() !== 304 || $this->manager->epoch() === null) {
                        throw new RuntimeException;
                    }
                    $this->error = null;

                    if (! $this->serving && $this->manager->ready() && $this->onReady !== null) {
                        ($this->onReady)();
                        $this->serving = true;
                    }
                    $this->report();
                } catch (Throwable) {
                    $this->error = 'registry.apply_failed';
                    $this->retry();
                }
            },
            function (): void {
                $this->error = 'registry.unreachable';
                $this->retry();
            },
        )->finally(function (): void {
            $this->fetching = false;

            if ($this->pending) {
                $this->pending = false;
                Loop::futureTick($this->refresh(...));
            }
        });
    }

    private function retry(): void
    {
        if ($this->retryTimer === null) {
            $this->retryTimer = Loop::addTimer(5, function (): void {
                $this->retryTimer = null;
                $this->refresh();
            });
        }
    }

    public function report(): void
    {
        if ($this->reporting || ! $this->manager->ready()) {
            return;
        }
        $this->reporting = true;
        $url = $this->processUrl();
        $body = json_encode([
            'boot_id' => $this->bootId,
            'sequence' => ++$this->reportSequence,
            'epoch' => $this->manager->epoch(),
            'revision' => $this->manager->revision(),
            'state' => $this->serving ? 'serving' : 'starting',
            'app_count' => $this->manager->all()->count(),
            'connections' => $this->connections->counts(),
            'messages' => app(Measurements::class)->totals(array_values($this->manager->all()->map(static fn (Application $app): string => $app->id())->all())),
            'error' => $this->error,
        ], JSON_THROW_ON_ERROR);
        $this->client->put($url, $this->headers() + ['Content-Type' => 'application/json'], $body)
            ->then(static function (): void {}, static function (): void {})
            ->finally(function (): void {
                $this->reporting = false;
            });
    }

    private function processUrl(): string
    {
        return rtrim((string) config('reverb-manager.registry_url'), '/').'/processes/'.rawurlencode((string) config('reverb-manager.process_id'));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.config('reverb-manager.registry_token'), 'Accept' => 'application/json'];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['boot_id' => $this->bootId, 'serving' => $this->serving, 'error' => $this->error];
    }
}
