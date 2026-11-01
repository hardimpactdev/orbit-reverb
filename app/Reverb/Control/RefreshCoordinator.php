<?php

declare(strict_types=1);

namespace App\Reverb\Control;

use App\Reverb\Connections;
use App\Reverb\RegistryRestartRequired;
use App\Reverb\ReverbManager;
use Closure;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

final class RefreshCoordinator
{
    private ?RegistryRestartRequired $restartRequired = null;

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

    /** @var list<Deferred<array<string,mixed>>> */
    private array $synchronizations = [];

    public function __construct(private readonly ReverbManager $manager, private readonly Connections $connections)
    {
        $this->bootId = (string) Str::uuid();
        $this->client = (new Browser)->withTimeout((float) config('registry.request_timeout'))
            ->withFollowRedirects(false)->withRejectErrorResponse(false)->withResponseBuffer(16 * 1024 * 1024);
    }

    public function start(Closure $onReady): void
    {
        foreach (['registry_url', 'registry_token', 'process_id'] as $key) {
            if (! is_string(config('registry.'.$key)) || config('registry.'.$key) === '') {
                throw new RuntimeException('Reverb registry configuration is incomplete.');
            }
        }
        foreach (['request_timeout', 'reconcile_interval', 'heartbeat_interval'] as $key) {
            $value = config('registry.'.$key);

            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
                throw new RuntimeException('Reverb registry timing configuration is invalid.');
            }
        }
        $url = (string) config('registry.registry_url');

        if (! str_starts_with($url, 'https://') && ! (app()->environment('testing') && str_starts_with($url, 'http://127.0.0.1:'))) {
            throw new RuntimeException('Reverb registry requires HTTPS.');
        }
        $this->onReady = $onReady;
        Loop::addPeriodicTimer((float) config('registry.reconcile_interval'), $this->refresh(...));
        Loop::addPeriodicTimer((float) config('registry.heartbeat_interval'), $this->report(...));
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
        $waiting = $this->synchronizations;
        $this->synchronizations = [];

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
        $acknowledged = false;
        $headers = $this->headers();

        if ($this->manager->epoch() !== null) {
            $headers['If-None-Match'] = '"'.$this->manager->epoch().':'.$this->manager->revision().'"';
        }
        $this->client->get((string) config('registry.registry_url'), $headers)->then(
            function (ResponseInterface $response) use ($waiting, &$acknowledged): ?PromiseInterface {
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
                    if ($waiting !== []) {
                        return $this->sendReport()->then(static function (bool $accepted) use (&$acknowledged): void {
                            $acknowledged = $accepted;
                        });
                    }
                    $this->report();
                } catch (RegistryRestartRequired $exception) {
                    $this->restartRequired = $exception;
                    Loop::stop();
                } catch (Throwable) {
                    $this->error = 'registry.apply_failed';
                    $this->retry();
                }

                return null;
            },
            function (): void {
                $this->error = 'registry.unreachable';
                $this->retry();
            },
        )->finally(function () use ($waiting, &$acknowledged): void {
            $this->fetching = false;
            $this->finishSynchronizations($waiting, $acknowledged);

            if ($this->pending) {
                $this->pending = false;
                Loop::futureTick($this->refresh(...));
            }
        });
    }

    /**
     * One existing registry refresh; failures return without waiting for background retries.
     *
     * @return PromiseInterface<array<string,mixed>>
     */
    public function synchronize(): PromiseInterface
    {
        $completion = new Deferred;
        if (! $this->registered || ! $this->serving) {
            $this->finishSynchronizations([$completion]);

            return $completion->promise();
        }
        $this->synchronizations[] = $completion;
        $this->refresh();

        return $completion->promise();
    }

    /** @param list<Deferred<array<string,mixed>>> $waiting */
    private function finishSynchronizations(array $waiting, bool $acknowledged = false): void
    {
        if ($waiting === []) {
            return;
        }
        $successful = $acknowledged && $this->error === null && $this->restartRequired === null;
        $result = [
            'process_id' => config('registry.process_id'), 'boot_id' => $this->bootId,
            'epoch' => $this->manager->epoch(), 'revision' => $this->manager->revision(),
        ];
        $complete = function () use ($waiting, $result, $successful): void {
            $result['state'] = $successful && $this->restartRequired === null && $this->serving && $this->manager->ready()
                && $this->manager->epoch() === $result['epoch'] && $this->manager->revision() === $result['revision']
                ? 'serving' : 'pending';
            foreach ($waiting as $completion) {
                $completion->resolve($result);
            }
        };
        if (! $successful) {
            $complete();
        } else {
            $this->connections->whenSettled($complete);
        }
    }

    public function assertCurrentHistory(): void
    {
        if ($this->restartRequired !== null) {
            throw $this->restartRequired;
        }
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
        $this->sendReport()->finally(function (): void {
            $this->reporting = false;
        });
    }

    /** @return PromiseInterface<bool> */
    private function sendReport(): PromiseInterface
    {
        $url = $this->processUrl();
        $body = json_encode([
            'boot_id' => $this->bootId,
            'sequence' => ++$this->reportSequence,
            'epoch' => $this->manager->epoch(),
            'revision' => $this->manager->revision(),
            'state' => $this->serving ? 'serving' : 'starting',
            'app_count' => $this->manager->all()->count(),
            'connections' => $this->connections->counts(),
            'error' => $this->error,
        ], JSON_THROW_ON_ERROR);

        return $this->client->put($url, $this->headers() + ['Content-Type' => 'application/json'], $body)
            ->then(static fn (ResponseInterface $response): bool => $response->getStatusCode() === 204, static fn (): bool => false);
    }

    private function processUrl(): string
    {
        return rtrim((string) config('registry.registry_url'), '/').'/processes/'.rawurlencode((string) config('registry.process_id'));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.config('registry.registry_token'), 'Accept' => 'application/json'];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['boot_id' => $this->bootId, 'serving' => $this->serving, 'error' => $this->error];
    }
}
