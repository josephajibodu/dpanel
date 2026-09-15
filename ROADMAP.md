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
