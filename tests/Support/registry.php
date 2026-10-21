<?php

declare(strict_types=1);

function registryApp(string $id, string $secret = 'test-secret', int $generation = 1): array
{
    return [
        'app_id' => $id, 'key' => $id.'-key', 'secret' => $secret,
        'credential_generation' => $generation,
        'ping_interval' => 60, 'activity_timeout' => 30,
        'allowed_origins' => ['*'], 'max_message_size' => 10000,
        'max_connections' => null, 'accept_client_events_from' => 'members',
        'rate_limiting' => null, 'options' => [],
    ];
}

function registrySnapshot(int $revision, array $apps): array
{
    return ['schema_version' => 1, 'epoch' => 'test-registry', 'revision' => $revision, 'apps' => $apps];
}
