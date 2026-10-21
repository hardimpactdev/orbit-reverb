<?php

declare(strict_types=1);

use Tests\Support\WireClient;

function rollingPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
}

function rollingWait(Closure $condition): void
{
    $deadline = microtime(true) + 7;
    do {
        if ($condition()) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Runtime did not converge within the test deadline.');
}

function rollingHttp(int $port, string $path, string $method = 'GET', ?array $body = null, string $token = 'notify-test'): int
{
    $context = stream_context_create(['http' => [
        'method' => $method, 'timeout' => 2, 'ignore_errors' => true,
        'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
    ]]);
    file_get_contents('http://127.0.0.1:'.$port.$path, context: $context);
    $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;

    return (int) explode(' ', $headers[0])[1];
}

function rollingSubscribe(WireClient $client, string $channel, string $key, string $secret, ?array $presence = null): void
{
    $data = ['channel' => $channel];
    $signature = $client->socketId.':'.$channel;

    if ($presence !== null) {
        $data['channel_data'] = json_encode($presence, JSON_THROW_ON_ERROR);
        $signature .= ':'.$data['channel_data'];
    }
    $data['auth'] = $key.':'.hash_hmac('sha256', $signature, $secret);
    $client->send(['event' => 'pusher:subscribe', 'data' => $data]);
    expect($client->event()['event'])->toBe('pusher_internal:subscription_succeeded');
}

function rollingPublish(int $port, string $app, string $secret, string $channel, string $event): int
{
    $body = ['name' => $event, 'channels' => [$channel], 'data' => '{"verified":true}'];
    $params = ['auth_key' => $app.'-key', 'auth_timestamp' => time(), 'auth_version' => '1.0', 'body_md5' => md5(json_encode($body, JSON_THROW_ON_ERROR))];
    ksort($params);
    $path = '/apps/'.$app.'/events';
    $params['auth_signature'] = hash_hmac('sha256', "POST\n".$path."\n".http_build_query($params), $secret);

    return rollingHttp($port, $path.'?'.http_build_query($params), 'POST', $body);
}

function rollingAssertDelivery(array $ports, array $clients, bool $scaling, string $event): void
{
    if ($scaling) {
        expect(rollingPublish($ports[0], 'b', 'test-secret', 'private-stable', $event))->toBe(200);
    } else {
        foreach ($ports as $port) {
            expect(rollingPublish($port, 'b', 'test-secret', 'private-stable', $event))->toBe(200);
        }
    }
    foreach ($clients as $i => $set) {
        try {
            expect($set['b']->event()['event'])->toBe($event);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('Missing '.$event.' on process '.$i.' with scaling '.(int) $scaling, previous: $exception);
        }
    }
}
