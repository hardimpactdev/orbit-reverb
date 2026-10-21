<?php

declare(strict_types=1);

namespace App\Reverb\Control;

use Laravel\Reverb\Servers\Reverb\Http\Response;
use Psr\Http\Message\RequestInterface;

final readonly class NotificationController
{
    public function __construct(private RefreshCoordinator $refresh) {}

    public function __invoke(RequestInterface $request): Response
    {
        $token = config('registry.notification_token');

        if (! is_string($token) || $token === '' || ! hash_equals('Bearer '.$token, $request->getHeaderLine('Authorization'))) {
            return new Response(status: 403);
        }

        if ($request->hasHeader('Upgrade')) {
            return new Response(status: 400);
        }
        $body = (string) $request->getBody();

        if (strlen($body) > 1024) {
            return new Response(status: 413);
        }
        $hint = json_decode($body, true);

        if (! is_array($hint) || ! is_string($hint['epoch'] ?? null) || strlen($hint['epoch']) > 128
            || ! is_int($hint['revision'] ?? null) || $hint['revision'] < 1) {
            return new Response(status: 422);
        }
        $this->refresh->refresh();

        return new Response(status: 202);
    }
}
