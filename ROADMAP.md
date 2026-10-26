# Flitops Roadmap

Planned feature work, not yet scheduled. See [`PRODUCTION_CHECKLIST.md`](PRODUCTION_CHECKLIST.md) for reliability/ops gaps in the running system.

---

## Server metrics collection agent (Go)

Server CPU/memory/disk stats (`app/Jobs/CollectServerMetricsJob.php`) are currently gathered by SSHing into each server on a schedule (every 10 minutes) and running `free`/`df`/`/proc/loadavg`. This is simple and reuses existing SSH infra, but has real limits:

- Per-server SSH round-trip overhead scales linearly with fleet size.
- 10-minute resolution — fine for a dashboard glance, too coarse for alerting or graphing spikes.
- No push path: a server can't proactively report an incident (disk filling up, OOM) between polls.

Planned replacement: a small daemon (likely Go, for a single static binary with no runtime dependencies) installed on each server during provisioning that collects metrics locally and pushes them to Flitops on a short interval, or exposes them for pull over an authenticated local endpoint. Would let the SSH-based `CollectServerMetricsJob` retire once shipped.

- [ ] Design push/pull transport and auth between the agent and Flitops
- [ ] Build the Go agent (metrics collection + delivery)
- [ ] Add agent install/update step to server provisioning
- [ ] Migrate `Metric` writes from `CollectServerMetricsJob` to the agent's ingestion path
- [ ] Retire the SSH-polling job once the agent is the source of truth

---

## Interactive web terminal

There is no way to get a shell on a managed server from the Flitops UI today. Deploy and provisioning output streams one way over Reverb, and "Run command" (`RunSiteCommandJob`) only shows output after the command finishes. An interactive terminal needs low-latency traffic in both directions (keystrokes up, output down), which neither Reverb nor SSE/Mercure fits: Reverb relays messages between browsers rather than into a live SSH session, every broadcast goes through the `broadcasts` queue and a database write, and SSE is one-way and would hold a PHP-FPM worker per open stream.

Planned design: a small, separate terminal gateway process (could later fold into the server agent) that holds an SSH PTY session per terminal and bridges it to the browser over its own WebSocket. Laravel stays the source of authorization and never proxies the bytes itself. This is shell access to production servers from a browser, so the security controls are part of the feature, not a follow-up.

- [ ] Build the gateway: accept a WebSocket, open an SSH PTY to the server with the existing server key, pipe bytes both ways, handle resize
- [ ] Short-lived, single-use signed token issued by Laravel (user, server, expiry ~60s); the gateway rejects any connection without a valid one
- [ ] Restrict to team Admins, and require password confirmation (or 2FA) before a session opens
- [ ] Audit log: who opened a session, on which server, when it started and ended (no keystroke recording, since that would capture passwords typed at prompts)
- [ ] Close idle sessions automatically and cap session length
- [ ] Frontend terminal with xterm.js on the server detail page
- [ ] Run the gateway as a supervised worker behind the existing nginx proxy, like Reverb
