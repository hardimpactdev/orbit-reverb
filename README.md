# Orbit Reverb

A Laravel [Reverb](https://reverb.laravel.com) server that accepts WebSocket
connections over the Pusher protocol for several apps. Orbit-specific
application code loads app changes from the Gateway without restarting Reverb.

## Configuration

The Gateway registry supplies apps and secrets at runtime. The environment
configures access to it. See `.env.example` for the full list:

| Variable | Meaning |
| --- | --- |
| `REVERB_REGISTRY_URL` | Gateway snapshot endpoint. |
| `REVERB_PROCESS_ID` | Runtime identity provisioned by the Gateway. |
| `REVERB_REGISTRY_TOKEN` | Restricted credential for snapshot pulls and apply reports. |
| `REVERB_NOTIFICATION_TOKEN` | Credential for revision notifications. |
| `REVERB_HOST` | Public hostname clients connect to: `reverb.orbit`. |
| `REVERB_PORT` | Public port: `443`. |
| `REVERB_SCHEME` | Public scheme: `https` (so clients use `wss://`). |
| `REVERB_SERVER_HOST` | Local bind address for the server process: `0.0.0.0`. |
| `REVERB_SERVER_PORT` | Local bind port for the server process: `8080`. |

Each app has its own channels. Reverb checks each publish request against
the secret of the app in its URL, so one app's credentials cannot publish
on another app's channels.

`ManagedPusherServer` selects the sender's app before handling each
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

Orbit runs this process on the Node with the websocket role. It waits for a
fresh registry snapshot before accepting connections:

```bash
php artisan orbit:reverb:start --host=0.0.0.0 --port=8080
```

Caddy in front of it (managed by Orbit) terminates TLS and handles the
WebSocket upgrade for `reverb.orbit`, then forwards the connection to that
port.
