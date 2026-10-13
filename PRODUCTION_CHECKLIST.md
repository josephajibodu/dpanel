# Flitops Production Readiness Checklist
### For hosting Bouclay reliably

This checklist covers what a deployment platform needs beyond basic provisioning, so that Flitops does not become the weak link under a billing system that depends on it running correctly every hour of every day.

Last reviewed: 2026-10-13 (heartbeat, uptime alerts, SSL expiry alerts, self-hosting readiness). Originally ordered on 2026-09-14, after a real incident (an expired wildcard certificate broke a live site's HTTPS because Flitops's own scheduler had never actually run). Ordered by priority based on what that incident revealed, not the original draft order.

---

## 0. Control Plane Resilience (found 2026-09-14 — highest priority)
Flitops itself currently runs as a foreground dev process on the operator's laptop (`composer run dev`, no process supervisor). A sleep, reboot, network drop, or closed terminal kills every queue worker silently — no deploys, no cert renewals, no provisioning — with nothing to alert anyone. This undermines every other item below, since they all depend on Flitops's own background jobs actually running.

- [ ] Run the stack under a supervisor so a crash restarts it automatically — plan: Horizon and Reverb as Flitops-managed supervisord workers (cutover step 6)
- [ ] Move Flitops itself onto an always-on server, deployed as a Flitops site — code is ready as of 2026-10-13; follow the cutover steps below
- [ ] Confirm `php artisan schedule:run` is wired into real system cron on wherever Flitops ends up running — plan: a Flitops site cron job (cutover step 7)
- [x] After any code deploy, restart queue workers so they pick up new code — see item 1
- [x] Heartbeat so a dead scheduler or queue is noticed (2026-10-13) — `SendSchedulerHeartbeatJob` runs via the scheduler on the queue every 5 minutes and pings `SCHEDULER_HEARTBEAT_URL` (Healthchecks.io, Better Stack, …). The external monitor alerts when pings stop. **Set `SCHEDULER_HEARTBEAT_URL` on the real install** (period 5 min, grace 5–10 min) or nothing is watched.

### Cutover steps (laptop → server)
Verified against the code on 2026-10-13. `flitops.example.com`, user `artisan` and PHP 8.4 are placeholders.

1. **Provision** (from the laptop Flitops): a dedicated Ubuntu 24.04 server (≥ 2 vCPU / 4 GB) with PostgreSQL and Redis; database + user `flitops`. Lower the DNS TTL in advance.
2. **Site**: Laravel site for `flitops.example.com` on the Flitops repo, branch `main`. Point DNS, issue SSL. Don't deploy yet.
3. **Environment** (before the first deploy — the build bakes in `VITE_*`):
   - `APP_KEY` = **the laptop's key exactly** (otherwise copied SSH keys/tokens can't be decrypted); `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://flitops.example.com`, `SESSION_SECURE_COOKIE=true`, `LOG_STACK=daily`
   - `DB_CONNECTION=pgsql` + server DB credentials; `QUEUE_CONNECTION=redis`, `REDIS_HOST=127.0.0.1`, `BROADCAST_CONNECTION=reverb`, `CACHE_STORE=database`, `SESSION_DRIVER=database`
   - Reverb: `REVERB_SERVER_HOST=127.0.0.1`, `REVERB_SERVER_PORT=8080`, `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`, `VITE_REVERB_HOST=flitops.example.com`, `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https`, `REVERB_ALLOWED_ORIGINS=flitops.example.com`, fresh app id/key/secret, `VITE_REALTIME_DEBUG=false`
   - `HORIZON_ADMINS=<you>` (else `/horizon` is 403), real SMTP, `ALERT_EMAIL=<you>`, `SCHEDULER_HEARTBEAT_URL=<monitor ping url>`
   - `FREE_DOMAIN`, `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ZONE_ID`, `ACME_BINARY=/home/artisan/.acme.sh/acme.sh`
   - GitHub/GitLab/Bitbucket client id/secret; leave `*_REDIRECT_URI` unset or point at the new host. Leave `SERVER_USER` unset (must match the laptop).
4. **Deploy** from the laptop; `https://flitops.example.com/up` returns 200.
5. **Reverb proxy**: add a Locations nginx file for the domain:
   `location ^~ /app/ { proxy_http_version 1.1; proxy_set_header Host $http_host; proxy_set_header Scheme $scheme; proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "Upgrade"; proxy_pass http://127.0.0.1:8080; }`
6. **Workers** (user `artisan`, 1 process, autostart/autorestart, with a log file): `php8.4 artisan horizon` and `php8.4 artisan reverb:start --host=127.0.0.1 --port=8080`.
7. **Cron** (user `artisan`, `* * * * *`): `php8.4 artisan schedule:run >> /dev/null 2>&1`.
8. **acme.sh** as `artisan`: `curl https://get.acme.sh | sh -s email=<you>`.
9. **Cutover window**:
   1. Laptop: wait until `/horizon` shows nothing pending/running (the laptop Redis isn't migrated), then `php artisan down`, stop the dev stack, and confirm `pgrep -fl "artisan (horizon|queue|schedule|reverb)"` prints nothing.
   2. Server: `php8.4 artisan down` in `current` (pauses its Horizon/scheduler).
   3. Laptop: `pg_dump --no-owner --no-acl -Fp -f flitops-cutover.sql <db>` (laptop `pg_dump` major ≤ server `psql`) and `tar czf certs.tgz -C storage/app certificates`; `scp` both to the server.
   4. Server: `dropdb`/`createdb -O flitops flitops`, load the dump with `psql`; untar certs into `shared/storage/app` and `chmod -R go-rwx` them; `php8.4 artisan migrate --force`.
   5. Repoint the copied wildcard `Certificate` rows at server paths (`WildcardCertificateIssuer::pathsFor()`), or delete the row and let `IssueWildcardCertificateJob` reissue — which also proves acme.sh works.
   6. Server: `php8.4 artisan up && php8.4 artisan horizon:terminate`.
   7. Laptop: rename the old database (e.g. `flitops_pre_cutover`) and point the laptop `.env` at a fresh dev DB, so a stray `composer run dev` can't run a second scheduler/queue against real servers. Delete both dump files once confident (they hold encrypted secrets).
   8. Update each OAuth app's callback to `https://flitops.example.com/auth/{provider}/callback` and any repo webhooks pointing at the old URL.
10. **Verify**: `/up` 200 and login works; `horizon:status` running and both workers RUNNING in `supervisorctl status`; exactly one `schedule:run` cron; heartbeat monitor goes green; websocket to `wss://…/app/` returns 101; server "Test connection" updates live; an on-demand backup completes; a throwaway free-domain site gets the wildcard cert; a self-deploy ends with the three restart lines; a team invite email arrives.
11. **If a self-deploy breaks Flitops** (don't rely on rollback yet — see item 3): `cd ~/flitops.example.com && ln -sfn releases/<previous> current && sudo service php8.4-fpm reload && cd current && php8.4 artisan horizon:terminate && php8.4 artisan reverb:restart`.

---

## 1. Deploy script doesn't restart queue workers — done (2026-09-15, corrected 2026-10-13)
`ProjectType::laravelDeployScript()` ran composer/npm/migrate/cache commands but never `artisan queue:restart` or `artisan horizon:terminate`. PHP queue workers cache job/listener code in memory and don't hot-reload — every deploy of any queue-using app (Bouclay included, and a self-hosted Flitops especially) silently kept running stale code until someone manually restarted workers. This is exactly the bug that made a deployment's commit message appear one deploy behind.

- [x] Add a queue-worker restart step to the default deploy script(s) — `$PHP artisan queue:restart` now runs at the end of the Laravel default deploy script, after config/route/view/event caching. This only affects sites created going forward; sites created before 2026-09-15 have their own saved deploy script and need this line added manually via the "Deploy script" page if they run queue workers.
- [x] Corrected 2026-10-13: the 2026-09-15 restart ran *before* the `current` swap, so workers came straight back up on the old release (and Horizon always respawns from the release its master booted from). The deploy strategy now runs `queue:restart`, plus `horizon:terminate` / `reverb:restart` when those packages are installed, *after* the swap (`ProjectType::postActivationScript()`). This applies to every Laravel site, including ones with saved scripts — a `queue:restart` left in a saved script is now redundant and can be removed. Note: these run under `set -e`, so if one fails the deploy is reported failed even though the new release is already live.

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
- [x] Ability to run pre/post-deploy hooks (migrations, cache clear, worker restarts — see item 1)
- [ ] Rollback is broken (found 2026-10-13): `RollbackSiteJob` runs the swap without the deploy script's variables, so `$PHP_FPM` is empty, the FPM reload fails and every rollback is reported failed (although the swap happened); it also never restarts workers. Fix: give it the same preamble and run `postActivationScript()` after.

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
- [x] Monitoring that Flitops's own scheduler is actually firing (not just that the server is up) — scheduler heartbeat, see item 0 (needs `SCHEDULER_HEARTBEAT_URL` set)
- [x] Alert if scheduled tasks stop running — the external heartbeat monitor alerts when pings stop (covers a dead scheduler *and* dead queue workers)

*Why it matters: if the scheduler silently stops, dunning retries and trial expirations silently stop with it — and nobody notices until customers complain. This already happened once, to Flitops's own cert renewal.*

---

## 8. SSL Renewal Reliability — partially done
- [x] Initial provisioning
- [x] Self-healing safety net: a stale/expired wildcard certificate is now renewed (or safely falls back to a still-valid one) automatically the moment a new site is provisioned, instead of blindly installing whatever's on record (fixed 2026-09-14)
- [ ] Confirmed auto-renewal actually fires reliably on schedule — depends on Control Plane Resilience above (item 0)
- [x] Alert before expiry if renewal fails (2026-10-13) — `CheckCertificateExpiryJob` runs daily at 09:00, reads the expiry of the certificate each server *actually serves* (TLS handshake to the server IP with the hostname as SNI) and emails at 14/7/3/1/0 days left, once per threshold. Custom domains alert the team owner; the wildcard alerts `ALERT_EMAIL` (**set it**, otherwise wildcard alerts are only logged). Issuance/renewal failures also email now.
- [x] SSL expiry visible in the UI + manual "Renew SSL" action
- [ ] **Custom-domain renewals never reach nginx** (found 2026-10-13): certbot renews `/etc/letsencrypt/live/<host>`, but nothing copies it into `/etc/nginx/ssl/domains/...` or reloads nginx, so every custom domain serves an expired cert ~90 days after issuance unless "Renew SSL" is clicked. Fix: a certbot deploy hook per certificate. Until then, the 14-day alert is the safety net.
- [ ] No way to re-push the wildcard cert to a server whose copy failed (`DistributeWildcardCertificateJob` only runs on reissue; the daily expiry check does flag a stale copy)

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
- [x] Basic uptime checks (2026-10-13) — every live site's primary domain is HTTP-checked every 5 minutes (`CheckAllSitesUptimeJob`); connection errors, timeouts and 5xx count as down. Up/Down and last check show on the site overview. Server-level (SSH) reachability alerts are still open.
- [ ] Application-level health checks (is the queue backed up, is the DB reachable)
- [ ] Alerts to phone/Slack/email on failure — **email done** (site down after 2 consecutive failures, and recovered, to the team owner); Slack/SMS still open
- [x] Historical uptime/incident log — `site_incidents` records start/resolve/reason per site; no UI to browse past incidents yet

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

## Also fixed 2026-10-13 (found during the self-hosting audit)
- Production Horizon supervisors now set `tries` (left unset, Horizon retried failing jobs forever and never called `failed()`), `supervisor-ssh` timeout raised to 600s so auto-balancing can't kill `default`-queue jobs mid-run, and `retry_after` raised to 3660s so hour-long backups/restores aren't handed to a second worker. `HorizonProductionConfigTest` guards this.
- Reverb's `allowed_origins` fallback now uses the `APP_URL` host (Reverb compares hosts only), so websockets aren't all rejected when `REVERB_ALLOWED_ORIGINS` is unset.
- Wildcard certificate paths are stored through `shared/storage`, so they survive release pruning under zero-downtime deploys.
- Scheduled backup failures now email the team owner (previously sent to no one because the lookup used a non-existent `Team::user` relation).

## Open, lower priority (found 2026-10-13)
- [ ] **Security:** `.env.example` (committed) contains what looks like a real `GITHUB_CLIENT_SECRET` — rotate it in GitHub and blank it in the file. Don't copy the laptop `.env` to the server as-is either (it has commented-out provider tokens and a server password).
- [ ] Production Horizon also runs the shared-defaults `supervisor-1` (auto-scaling to 10 processes) — remove it or size the server for ~26 PHP processes at peak.
- [ ] Workers have no `stopwaitsecs` (Horizon recommends ≥ the longest job, 3600s).
- [ ] `horizon:terminate` waits for the longest running job (up to an hour for a backup) with `fast_termination` off — consider enabling it.
- [ ] Editing a site's environment only runs `queue:restart`; Horizon's master and Reverb keep old values — reuse `postActivationScript()`.
- [ ] `failed_jobs` and the single `laravel.log` grow forever — schedule `queue:prune-failed` and use `LOG_STACK=daily`.
- [ ] 10 tests were already failing before 2026-10-13: `NginxConfigServiceTest` (×4, PHP-FPM socket path), `SiteManagementTest` env page (deferred prop), `PhpRuntimeResolverTest` and `KeyGeneratorTest` (unit tests not booting the app). The full suite also needs `memory_limit` above 128M.

## How to use this
Work top to bottom — item 0 (Control Plane Resilience) undermines everything below it, so it's the actual current bottleneck regardless of what looks more urgent by itself. As of 2026-10-13 the code side of item 0 is ready; what's left is carrying out the cutover steps. The custom-domain renewal gap (item 8) and broken rollback (item 3) are the most important code gaps left before relying on Flitops for Bouclay. After those, items 5 and 9 (audit trail, log access) are the largest remaining gaps. Item 12 stays last; it's the most deferrable by a wide margin.
