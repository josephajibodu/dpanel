# Flitops Production Readiness Checklist
### For hosting Bouclay reliably

This checklist covers what a deployment platform needs beyond basic provisioning, so that Flitops does not become the weak link under a billing system that depends on it running correctly every hour of every day.

Last reviewed: 2026-09-14, after a real incident (an expired wildcard certificate broke a live site's HTTPS because Flitops's own scheduler had never actually run). Ordered by priority based on what that incident revealed, not the original draft order.

---

## 0. Control Plane Resilience (found 2026-09-14 — highest priority)
Flitops itself currently runs as a foreground dev process on the operator's laptop (`composer run dev`, no process supervisor). A sleep, reboot, network drop, or closed terminal kills every queue worker silently — no deploys, no cert renewals, no provisioning — with nothing to alert anyone. This undermines every other item below, since they all depend on Flitops's own background jobs actually running.

- [ ] Run the stack under a supervisor (pm2, supervisord, etc.) so a crash restarts it automatically
- [ ] Move Flitops itself onto an always-on server, with Horizon under systemd
- [ ] Confirm `php artisan schedule:run` is wired into real system cron on wherever Flitops ends up running (`* * * * * cd {path} && php artisan schedule:run`) — this was found missing and is what let the wildcard cert renewal job never fire
- [ ] After any code deploy, restart queue workers so they pick up new code — see item 1 below, this needs to become part of the deploy script, not a manual step

---

## 1. Deploy script doesn't restart queue workers — done (2026-09-15)
`ProjectType::laravelDeployScript()` ran composer/npm/migrate/cache commands but never `artisan queue:restart` or `artisan horizon:terminate`. PHP queue workers cache job/listener code in memory and don't hot-reload — every deploy of any queue-using app (Bouclay included, and a self-hosted Flitops especially) silently kept running stale code until someone manually restarted workers. This is exactly the bug that made a deployment's commit message appear one deploy behind.

- [x] Add a queue-worker restart step to the default deploy script(s) — `$PHP artisan queue:restart` now runs at the end of the Laravel default deploy script, after config/route/view/event caching. This only affects sites created going forward; sites created before 2026-09-15 have their own saved deploy script and need this line added manually via the "Deploy script" page if they run queue workers.

---

## 2. Provisioning (foundation) — done
- [x] Server creation (VPS spin-up)
- [x] Website/site setup on a server
- [x] Database creation — MySQL and PostgreSQL both fully wired end-to-end (provisioning, CRUD actions, `.env` templating, UI selection); confirmed via audit 2026-09-14
- [x] SSL certificate provisioning

---

## 3. Deployment Mechanics — done (2026-09-14)
- [x] Zero-downtime deploys via atomic symlink swap (releases/`current` pattern)
- [x] One-click rollback to the previous release if a deploy breaks something
- [x] Deploy history/log (what was deployed, when, by whom)
- [x] Ability to run pre/post-deploy hooks (migrations, cache clear — queue restart still missing, see item 1)

---

## 4. Process & Queue Management — done (pre-existing)
- [x] Supervisor-style process manager for queue workers
- [x] Workers auto-restart on crash (supervisord `autorestart`)
- [x] Dashboard visibility into worker status (running/stopped/crashed)
- [x] Access to worker logs without SSH — per-worker stdout log fetch exists; general app/server logs do not, see item 8

---

## 5. Environment & Secrets Management — mostly done
- [x] Per-site environment variable storage
- [x] Secrets encrypted at rest
- [x] Easy update of env vars without manual SSH + file editing
- [ ] Audit trail of who changed what env var and when

*Why it matters: Nomba/Paystack/Flutterwave API keys and DB credentials live here. This is the most sensitive layer of the whole stack.*

---

## 6. Database Access & Tooling — done (2026-09-15)
- [x] A place in the UI to copy a database's connection string/credentials for use in DB clients (TablePlus, DBeaver, Postico, etc.) — shipped as an SSH-tunnel "Connect" dialog per database user (SSH host/port/user, DB host/port/name/username, reveal-on-click password, copyable tunnel command). No database port is exposed publicly.
- [x] One-click password reset per database user, matching Forge
- [ ] (optional, later) in-app read-only DB query browser — noted, not prioritized

---

## 7. Scheduled Tasks (Cron)
- [x] Laravel scheduler cron entry configured automatically per site (site-level cron jobs work correctly)
- [ ] Monitoring that Flitops's own scheduler is actually firing (not just that the server is up) — root cause of the incident; see Control Plane Resilience above
- [ ] Alert if scheduled tasks stop running

*Why it matters: if the scheduler silently stops, dunning retries and trial expirations silently stop with it — and nobody notices until customers complain. This already happened once, to Flitops's own cert renewal.*

---

## 8. SSL Renewal Reliability — partially done
- [x] Initial provisioning
- [x] Self-healing safety net: a stale/expired wildcard certificate is now renewed (or safely falls back to a still-valid one) automatically the moment a new site is provisioned, instead of blindly installing whatever's on record (fixed 2026-09-14)
- [ ] Confirmed auto-renewal actually fires reliably on schedule — depends on Control Plane Resilience above (item 0)
- [ ] Alert before expiry if renewal fails
- [ ] SSL expiry visible in the UI + manual "Renew SSL" action — in progress in a separate session (task_7340b16c) as of 2026-09-14

*Why it matters: a silently expired cert breaks every incoming webhook from Nomba/Stripe with no warning — this is a subtle, high-impact failure mode, and the one that already happened.*

---

## 9. Log Access — not done
- [ ] Application logs visible from dashboard
- [ ] Server logs visible from dashboard
- [ ] Searchable/filterable logs (not just a raw tail)
- [ ] No SSH required for routine debugging

There's an unfinished `ServerLog` model/migration (`type`, `disk`, `is_remote` columns) that looks scaffolded for exactly this and was never built out — reuse it rather than starting fresh.

---

## 10. Backups — done (2026-09-15)
- [x] Automated, scheduled database backups — per-database schedule (hourly/daily/weekly), fanned out hourly via `CollectDueBackupsJob` the same way server metrics polling works
- [x] One-click restore — gated behind typing the database name to confirm, since it overwrites live data
- [x] Backup verification (confirm backups are actually restorable, not just "completed") — every backup is imported into a throwaway scratch database on the same server, then dropped, before being marked completed
- [x] Off-server backup storage (not just on the same VPS) — new per-team "Storage Providers" (Cloudflare R2 / S3, bring-your-own-credentials, same model as `ProviderAccount`); the dump streams directly from the managed server to the bucket via the AWS CLI (adapted from Laravel Forge's own `backup.sh`), never passing through the Flitops app server

*Why it matters: Bouclay's database is customers' billing history and subscription state. This is not recoverable data if lost.*

**Not yet done, deliberately out of scope for this pass:** alerting/monitoring beyond a single failure email notification (that's item 11), cross-server/cross-database restore (v1 restores in place only), incremental/differential backups (full dumps only).

---

## 11. Monitoring & Alerting — not done
- [ ] Basic uptime checks (is the server/site reachable)
- [ ] Application-level health checks (is the queue backed up, is the DB reachable)
- [ ] Alerts to phone/Slack/email on failure — not just a dashboard you have to check
- [ ] Historical uptime/incident log

*Why it matters: a day of silent failure on Bouclay means a day of failed payments nobody caught.*

---

## 12. Multi-Environment Support — most deferrable, unchanged
- [ ] Staging environment that mirrors production
- [ ] Ability to test billing logic changes safely before touching real subscriptions/money
- [ ] Easy promotion path from staging to production

---

## Also fixed 2026-09-14 (housekeeping, not originally on this list)
- Site creation no longer fails when Cloudflare reports a DNS record already exists (now reused instead of erroring).
- Site deletion now correctly cleans up per-domain SSL certificate directories, nginx snippet directories, and workers/cron jobs tied to the site — all three were previously silently orphaned on the server.

---

## How to use this
Work top to bottom — item 0 (Control Plane Resilience) undermines everything below it, so it's the actual current bottleneck regardless of what looks more urgent by itself. Items 5 and 9 (audit trail, log access) are the largest remaining real gaps now that backups (item 10) are done. Item 12 stays last; it's the most deferrable by a wide margin.
