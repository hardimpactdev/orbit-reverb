<?php

declare(strict_types=1);

namespace App\Reverb\Control;

use App\Reverb\ReverbManager;
use Laravel\Reverb\Exceptions\InvalidApplication;
use Laravel\Reverb\Servers\Reverb\Http\Response;
use Psr\Http\Message\RequestInterface;
use React\Promise\PromiseInterface;

/** Apply the existing registry and return public confirmation from this live boot. */
final readonly class RegistryApplyController
{
    public function __construct(private RefreshCoordinator $refresh, private ReverbManager $manager) {}

    public function __invoke(RequestInterface $request): Response|PromiseInterface
    {
        $token = config('registry.registry_token');
        if (! is_string($token) || $token === '' || ! hash_equals('Bearer '.$token, $request->getHeaderLine('Authorization'))) {
            return $this->refuse('websocket.registry_unauthorized', 403);
        }
        if ($request->hasHeader('Upgrade')) {
            return $this->refuse('websocket.registry_invalid', 400);
        }
        $body = (string) $request->getBody();
        if (strlen($body) > 4096) {
            return $this->refuse('websocket.registry_invalid', 413);
        }
        $intent = json_decode($body, true);
        if (! $this->valid($intent)) {
            return $this->refuse('websocket.registry_invalid', 422);
        }
        if ($intent['process_id'] !== config('registry.process_id')) {
            return $this->refuse('websocket.registry_mismatch', 409);
        }

        return $this->refresh->synchronize()->then(function (array $runtime) use ($intent): Response {
            if ($runtime['state'] !== 'serving' || $runtime['epoch'] !== $intent['epoch'] || $runtime['revision'] < $intent['revision']) {
                return $this->refuse('websocket.registry_pending', 409, $runtime);
            }
            if (! $this->preserves($intent)) {
                return $this->refuse('websocket.registry_superseded', 409, $runtime);
            }

            return new Response(['outcome' => 'succeeded', 'data' => ['backend' => 'applied'], 'runtime' => $runtime], headers: ['Cache-Control' => 'no-store, private']);
        });
    }

    private function valid(mixed $intent): bool
    {
        if (! is_array($intent) || array_diff(array_keys($intent), ['process_id', 'epoch', 'revision', 'kind', 'app_id', 'credential_generation']) !== []
            || ! is_string($intent['process_id'] ?? null) || $intent['process_id'] === '' || strlen($intent['process_id']) > 128
            || ! is_string($intent['epoch'] ?? null) || $intent['epoch'] === '' || strlen($intent['epoch']) > 128
            || ! is_int($intent['revision'] ?? null) || $intent['revision'] < 1
            || ! in_array($intent['kind'] ?? null, ['sync', 'create', 'destroy', 'rotate'], true)) {
            return false;
        }
        if ($intent['kind'] === 'sync') {
            return ($intent['app_id'] ?? null) === null && ($intent['credential_generation'] ?? null) === null;
        }

        return is_string($intent['app_id'] ?? null) && $intent['app_id'] !== '' && strlen($intent['app_id']) <= 128
            && ($intent['kind'] === 'rotate'
                ? is_int($intent['credential_generation'] ?? null) && $intent['credential_generation'] > 0
                : ($intent['credential_generation'] ?? null) === null);
    }

    private function preserves(array $intent): bool
    {
        if ($intent['kind'] === 'sync') {
            return true;
        }
        try {
            $app = $this->manager->findById($intent['app_id']);
        } catch (InvalidApplication) {
            return $intent['kind'] === 'destroy';
        }

        return $intent['kind'] === 'create' || ($intent['kind'] === 'rotate'
            && ($app->options()['orbit_credential_generation'] ?? null) === $intent['credential_generation']);
    }

    private function refuse(string $code, int $status, ?array $runtime = null): Response
    {
        $result = ['error_code' => $code];
        if ($runtime !== null) {
            $result['runtime'] = $runtime;
        }

        return new Response($result, $status, ['Cache-Control' => 'no-store, private']);
    }
}
