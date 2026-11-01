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
| `REVERB_NOTIFICATION_TOKEN` | Optional credential for revision hints; omit it when the Gateway uses periodic reconciliation. |
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
fresh registry snapshot before accepting connections. Only the registry URL, runtime identity and registry token are required; a missing notification token disables revision hints and does not prevent startup. The normal 30-second reconciliation still applies changes without reconnecting other apps:

```bash
php artisan orbit:reverb:start --host=0.0.0.0 --port=8080
```

Caddy in front of it (managed by Orbit) terminates TLS and handles the
WebSocket upgrade for `reverb.orbit`, then forwards the connection to that
port.

## Native registry confirmation

`POST /internal/orbit/registry/apply` makes one refresh through the existing registry integration. It waits for sockets revoked by that refresh to close, then returns public confirmation from this process. It uses Reverb's HTTP promise support and existing close-handshake deadline. It does not poll or wait for background retries. Gateway outage, unapplied revision or superseded intent returns HTTP 409; credentials and response bodies never enter the result.

Authenticate with the process's existing `REVERB_REGISTRY_TOKEN`. The request names `process_id`, `epoch`, positive `revision`, and `kind` (`sync`, `create`, `destroy` or `rotate`). Changes also name `app_id`; rotation requires its positive `credential_generation`. Other fields are refused. A later revision confirms create while its app still exists, rotation only while that generation remains, and destroy only while the app is absent. These match the Gateway's registry rules.

Success returns `outcome: succeeded`, `data.backend: applied`, and `runtime` with process ID, boot ID, epoch, revision and serving state. There are no app credentials, application lists, connection counts or provider diagnostics. A request naming another process is refused before refresh. WebSocket upgrades are refused on the internal control paths, including encoded paths. Caddy must continue to withhold these internal paths; native clients reach the local listener through the approved pinned SSH transport.

This replaces only the confirmation read: ordinary snapshot pulls, reports, 30-second reconciliation, application-scoped revocation and stock Pusher protocol remain in place. The Gateway records app intent before the request. Unlike the former Gateway report poll, this response certifies application directly on the current Reverb boot even if an asynchronous report has not yet reached the Gateway. It does not restart the process or create credentials.
