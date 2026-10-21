# Reverb Manager

`hardimpactdev/reverb-manager` applies versioned app snapshots to a running Laravel Reverb server. Adding an app preserves existing connections. Removing an app closes only its sockets with code 4001. Rotating credentials closes that app's sockets with code 4201.

The package uses the official `laravel/package-skeleton` at `794492f1d394a267c405a263c29d1d8f20b660c5`. It is internal to the orbit-reverb repository. Orbit owns app management and the future dashboard.

## Runtime contract

The server fetches an authenticated full snapshot before listening. Authenticated revision-only webhooks trigger an asynchronous pull. A 30-second reconciliation check repairs missed webhooks. During Gateway outages, a running server retains its last applied state; a cold start waits for a fresh snapshot.

Snapshots use schema version 1, an epoch, an increasing revision, and an `apps` list. Every app contains its Reverb configuration plus `credential_generation`. Replays are idempotent. A changed epoch requires explicit process re-enrollment. Reports contain no credentials and distinguish installed state from completed revocation.

Use `orbit:reverb:start` with `REVERB_REGISTRY_URL`, `REVERB_REGISTRY_TOKEN`, `REVERB_PROCESS_ID`, and `REVERB_NOTIFICATION_TOKEN` in protected runtime configuration. The Gateway provisions these values through protected input. Do not put tokens in command arguments. The notification endpoint belongs to the running Reverb listener and must be restricted by the management proxy.

## Development

Run `composer test` for Pest, formatting, type coverage, and static analysis. Real-socket tests must prove unchanged socket IDs for unaffected apps; automatic reconnect must not hide a drop.

The parent repository runs the package's PHP/Laravel compatibility matrix from `.github/workflows/tests.yml`. Locally, set `REVERB_TEST_REDIS_BINARY` to a Redis or Valkey executable before `composer test`. The test starts its own loopback server on an ephemeral port and removes it afterward. Without this variable the Redis wire case is skipped; the other wire case still uses two Reverb processes. Local Linux validation covers PHP 8.3–8.5 with Laravel 12/13, using both lowest and stable dependencies. All 12 combinations pass the package checks, including both two-process wire cases. Windows execution remains a CI check. The package explicitly requires React Promise 3 for asynchronous completion callbacks.

Reports include counters only for currently configured apps. They count WebSocket protocol messages and bytes, including control messages; outbound counts represent sends to sockets, not confirmed delivery. Removal clears that app's counters. Store rate history in Orbit if a later dashboard needs it.
