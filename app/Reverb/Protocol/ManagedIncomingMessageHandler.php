<?php

declare(strict_types=1);

namespace App\Reverb\Protocol;

use App\Reverb\ReverbManager;
use Laravel\Reverb\Application;
use Laravel\Reverb\Protocols\Pusher\PusherPubSubIncomingMessageHandler;
use Throwable;

final class ManagedIncomingMessageHandler extends PusherPubSubIncomingMessageHandler
{
    public function handle(string $payload): void
    {
        $event = json_decode($payload, true);

        if (! is_array($event)) {
            return;
        }

        if (in_array($event['type'] ?? null, ['message', 'terminate'], true)) {
            if (! is_string($event['application'] ?? null)) {
                return;
            }

            try {
                $origin = @unserialize($event['application'], ['allowed_classes' => [Application::class]]);

                if (! $origin instanceof Application) {
                    return;
                }

                $current = app(ReverbManager::class)->findById($origin->id());

                if (! hash_equals($current->secret(), $origin->secret())
                    || $current->key() !== $origin->key()
                    || ($current->options()['orbit_credential_generation'] ?? null) !== ($origin->options()['orbit_credential_generation'] ?? null)) {
                    return;
                }
            } catch (Throwable) {
                return;
            }
        }

        parent::handle($payload);
    }
}
