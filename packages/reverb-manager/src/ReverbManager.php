<?php

declare(strict_types=1);

namespace HardImpact\ReverbManager;

use Illuminate\Support\Collection;
use Laravel\Reverb\Application;
use Laravel\Reverb\ConfigApplicationProvider;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;
use SensitiveParameter;

final class ReverbManager implements ApplicationProvider
{
    private ?Snapshot $snapshot = null;

    /** @var array<string, Application> */
    private array $apps = [];

    /** @var array<string, Application> */
    private array $keys = [];

    public function __construct(private readonly Connections $connections) {}

    /** @param array<string, mixed> $data */
    public function apply(#[SensitiveParameter] array $data): bool
    {
        $next = Snapshot::fromArray($data);

        if ($this->snapshot !== null) {
            if ($next->epoch !== $this->snapshot->epoch) {
                throw new InvalidSnapshot;
            }

            if ($next->revision < $this->snapshot->revision) {
                return false;
            }

            if ($next->revision === $this->snapshot->revision) {
                if ($next->apps !== $this->snapshot->apps) {
                    throw new InvalidSnapshot;
                }

                return false;
            }
        }

        $apps = [];
        $keys = [];
        $revoked = [];
        foreach ($next->apps as $id => $config) {
            $old = $this->snapshot?->apps[$id] ?? null;

            if ($old !== null && $config['credential_generation'] < $old['credential_generation']) {
                throw new InvalidSnapshot;
            }
            $providerConfig = $config;
            $providerConfig['options']['orbit_credential_generation'] = $config['credential_generation'];
            $provider = new ConfigApplicationProvider(collect([$providerConfig]));
            $apps[$id] = $old === $config ? $this->apps[$id] : $provider->findById((string) $id);
            $keys[$config['key']] = $apps[$id];

            if ($old !== null && ($old['secret'] !== $config['secret'] || $old['key'] !== $config['key']
                || $old['credential_generation'] !== $config['credential_generation'])) {
                $revoked[(string) $id] = 4201;
            }
        }
        foreach ($this->apps as $id => $app) {
            if (! isset($apps[$id])) {
                $revoked[(string) $id] = 4001;
            }
        }

        $this->snapshot = $next;
        $this->apps = $apps;
        $this->keys = $keys;
        $this->connections->revoke($revoked);

        return true;
    }

    /** @return Collection<int, Application> */
    public function all(): Collection
    {
        return collect(array_values($this->apps));
    }

    public function findById(string $id): Application
    {
        return $this->apps[$id] ?? throw new InvalidApplication;
    }

    public function findByKey(string $key): Application
    {
        return $this->keys[$key] ?? throw new InvalidApplication;
    }

    public function epoch(): ?string
    {
        return $this->snapshot?->epoch;
    }

    public function revision(): int
    {
        return $this->snapshot->revision ?? 0;
    }

    public function ready(): bool
    {
        return $this->snapshot !== null && ! $this->connections->hasClosing();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['epoch' => $this->epoch(), 'revision' => $this->revision(), 'app_count' => count($this->apps)];
    }
}
