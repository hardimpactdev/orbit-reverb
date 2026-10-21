# Orbit Reverb

A plain Laravel [Reverb](https://reverb.laravel.com) server. It has no
business logic of its own: it accepts WebSocket connections over the Pusher
protocol for several Reverb apps. Orbit deploys and runs this app the same
way it deploys any other app; nothing in this repository is Orbit-specific.

## Configuration

The environment configures every Reverb app. See `.env.example` for the
full list; the values that matter are:

| Variable | Meaning |
| --- | --- |
| `REVERB_APPS` | JSON list of apps. Each entry has `app_id`, `key`, and `secret`. |
| `REVERB_HOST` | Public hostname clients connect to: `reverb.orbit`. |
| `REVERB_PORT` | Public port: `443`. |
| `REVERB_SCHEME` | Public scheme: `https` (so clients use `wss://`). |
| `REVERB_SERVER_HOST` | Local bind address for the server process: `0.0.0.0`. |
| `REVERB_SERVER_PORT` | Local bind port for the server process: `8080`. |

Each app has its own channels. Reverb checks each publish request against
the secret of the app in its URL, so one app's credentials cannot publish
on another app's channels.

`AppScopedPusherServer` selects the sender's app before handling each
WebSocket message. Reverb 1.11.1 checks client-event membership against a
shared channel manager without selecting the app first. Its 60-second
housekeeping jobs can leave that manager on another app and reject valid
members with error 4009. The server binding keeps membership checks in
the sender's app, including after housekeeping and interleaved app traffic.

Channel authorization happens in each app's own backend (the Orbit Gateway
for its app), not here. `allowed_origins` is `*` because origins don't gate
anything on this app.

Session, cache, and queue all use `file`/`sync` drivers so the app boots and
runs without a database. `/up` is Laravel's stock health check.

## Running it

Orbit runs this process on the gateway node:

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```

Caddy in front of it (managed by Orbit) terminates TLS and handles the
WebSocket upgrade for `reverb.orbit`, then forwards the connection to that
port.
