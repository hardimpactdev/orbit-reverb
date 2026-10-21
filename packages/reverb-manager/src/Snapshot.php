<?php

declare(strict_types=1);

namespace HardImpact\ReverbManager;

use SensitiveParameter;

final readonly class Snapshot
{
    /** @param array<string, array<string, mixed>> $apps */
    private function __construct(
        public string $epoch,
        public int $revision,
        #[SensitiveParameter] public array $apps,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(#[SensitiveParameter] array $data): self
    {
        if (($data['schema_version'] ?? null) !== 1
            || ! is_string($data['epoch'] ?? null) || $data['epoch'] === '' || strlen($data['epoch']) > 128
            || ! is_int($data['revision'] ?? null) || $data['revision'] < 1
            || ! is_array($data['apps'] ?? null) || ! array_is_list($data['apps']) || count($data['apps']) > 10000) {
            throw new InvalidSnapshot;
        }

        $apps = [];
        $keys = [];
        foreach ($data['apps'] as $app) {
            if (! is_array($app)) {
                throw new InvalidSnapshot;
            }
            foreach (['app_id', 'key', 'secret'] as $field) {
                if (! is_string($app[$field] ?? null) || $app[$field] === '' || strlen($app[$field]) > 256) {
                    throw new InvalidSnapshot;
                }
            }
            foreach (['credential_generation', 'ping_interval', 'activity_timeout', 'max_message_size'] as $field) {
                if (! is_int($app[$field] ?? null) || $app[$field] < 1 || $app[$field] > 2147483647) {
                    throw new InvalidSnapshot;
                }
            }

            if (isset($apps[$app['app_id']]) || isset($keys[$app['key']])
                || ! is_array($app['allowed_origins'] ?? null) || ! array_is_list($app['allowed_origins'])
                || ! in_array($app['accept_client_events_from'] ?? null, ['all', 'members', 'none'], true)
                || ! is_array($app['options'] ?? null)
                || ! array_key_exists('max_connections', $app)
                || ($app['max_connections'] !== null && (! is_int($app['max_connections']) || $app['max_connections'] < 1))) {
                throw new InvalidSnapshot;
            }
            foreach ($app['allowed_origins'] as $origin) {
                if (! is_string($origin)) {
                    throw new InvalidSnapshot;
                }
            }

            if (! array_key_exists('rate_limiting', $app)) {
                throw new InvalidSnapshot;
            }

            if ($app['rate_limiting'] !== null) {
                $rate = $app['rate_limiting'];

                if (! is_array($rate) || ! is_bool($rate['enabled'] ?? null)
                    || ! is_bool($rate['terminate_on_limit'] ?? null)
                    || ! is_int($rate['max_attempts'] ?? null) || $rate['max_attempts'] < 1
                    || ! is_int($rate['decay_seconds'] ?? null) || $rate['decay_seconds'] < 1) {
                    throw new InvalidSnapshot;
                }
            }
            ksort($app);
            $apps[$app['app_id']] = $app;
            $keys[$app['key']] = true;
        }
        ksort($apps);

        return new self($data['epoch'], $data['revision'], $apps);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['epoch' => $this->epoch, 'revision' => $this->revision, 'app_count' => count($this->apps)];
    }
}
