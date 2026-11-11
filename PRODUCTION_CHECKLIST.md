# Flitops Production Readiness Checklist
### For hosting Bouclay reliably

This checklist covers what a deployment platform needs beyond basic provisioning, so that Flitops does not become the weak link under a billing system that depends on it running correctly every hour of every day.

Last reviewed: 2026-11-11 (full audit, see "Full audit" near the end). Before that: 2026-11-07 (cutover steps re-checked for isolated sites, lower-priority items). Before that: 2026-10-13 (heartbeat, uptime alerts, SSL expiry alerts, self-hosting readiness). Originally ordered on 2026-09-14, after a real incident (an expired wildcard certificate broke a live site's HTTPS because Flitops's own scheduler had never actually run). Ordered by priority based on what that incident revealed, not the original draft order.

---

## 0. Control Plane Resilience (found 2026-09-14 — highest priority)
Flitops itself currently runs as a foreground dev process on the operator's laptop (`composer run dev`, no process supervisor). A sleep, reboot, network drop, or closed terminal kills every queue worker silently — no deploys, no cert renewals, no provisioning — with nothing to alert anyone. This undermines every other item below, since they all depend on Flitops's own background jobs actually running.

- [ ] Run the stack under a supervisor so a crash restarts it automatically — plan: Horizon and Reverb as Flitops-managed supervisord workers (cutover step 6)
- [ ] Move Flitops itself onto an always-on server, deployed as a Flitops site — code is ready as of 2026-10-13; follow the cutover steps below
- [ ] Confirm `php artisan schedule:run` is wired into real system cron on wherever Flitops ends up running — plan: a Flitops site cron job (cutover step 7)
- [x] After any code deploy, restart queue workers so they pick up new code — see item 1
- [x] Heartbeat so a dead scheduler or queue is noticed (2026-10-13) — `SendSchedulerHeartbeatJob` runs via the scheduler on the queue every 5 minutes and pings `SCHEDULER_HEARTBEAT_URL` (Healthchecks.io, Better Stack, …). The external monitor alerts when pings stop. **Set `SCHEDULER_HEARTBEAT_URL` on the real install** (period 5 min, grace 5–10 min) or nothing is watched.

### Cutover steps (laptop → server)
The full, step-by-step guide is in [GO_LIVE.md](GO_LIVE.md) (rewritten and rehearsed 2026-11-12). It replaces the steps that were here: it copies data only into a database built by the first deploy (so FlitOps-made database grants and the new migration names survive), handles the laptop's PostgreSQL 17 vs the server's 16, and repoints the wildcard certificate record.

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
- [x] Rollback is broken (found and fixed 2026-10-13): `RollbackSiteJob` ran the swap without the deploy script's variables, so `$PHP_FPM` was empty, the FPM reload failed and every rollback was reported failed (although the swap happened); it also never restarted workers. It now runs with the same preamble as a deploy and the shared `activate()` fragment (swap, FPM reload, then `postActivationScript()` from the rolled-back release).

---

## 4. Process & Queue Management — done (pre-existing)
- [x] Supervisor-style process manager for queue workers
- [x] Workers auto-restart on crash (supervisord `autorestart`)
- [x] Dashboard visibility into worker status (running/stopped/crashed)
- [x] Access to worker logs without SSH — per-worker stdout log fetch exists; general app/server logs do not, see item 8

---

## 5. Environment & Secrets Management — done (2026-11-08)
- [x] Per-site environment variable storage
- [x] Secrets encrypted at rest
- [x] Easy update of env vars without manual SSH + file editing
- [x] Audit trail of who changed what env var and when (2026-11-08) — every save on the Environment page writes to the shared team activity log (`activity_logs`, `ActivityLog::record()`, events in the `ActivityEvent` enum): the user, the time, and which keys were added, changed or removed (names only, never values), listed under "Change history" on that page. Saving identical content records nothing; a site's first save through FlitOps is marked as such because the earlier file is unknown. Other actions can be logged by adding an `ActivityEvent` case and one `ActivityLog::record()` call; a team-wide Activity page doesn't exist yet.

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
- [x] Custom-domain renewals reach nginx (2026-10-13): every issuance and "Renew SSL" installs a certbot deploy hook (`/etc/letsencrypt/renewal-hooks/deploy/flitops-domain-<id>.sh`) that copies the renewed cert into `/etc/nginx/ssl/domains/...` and reloads nginx; domain/site deletion removes it and runs `certbot delete` for the certificate. **Custom domains issued before this need "Renew SSL" clicked once** to get the hook.
- [x] A server whose copy of the wildcard cert failed kept serving the old one (`DistributeWildcardCertificateJob` only ran on reissue) — fixed 2026-11-07: the daily expiry check now re-sends the current certificate to every server still serving an older copy.
- [x] acme.sh would have failed under supervisord (fixed 2026-11-07): supervisord doesn't set `HOME` for the user it runs Horizon as, and acme.sh keeps its state in `$HOME/.acme.sh`. The issuer now passes the running account's own home folder.

*Why it matters: a silently expired cert breaks every incoming webhook from Nomba/Stripe with no warning — this is a subtle, high-impact failure mode, and the one that already happened.*

---

## 9. Log Access — partially done
- [ ] Application logs visible from dashboard
- [ ] Server logs visible from dashboard — partly: each Nginx site has its own error log, shown on the site's Logs tab (2026-10-30)
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
- [ ] **Security:** `.env.example` (committed) contained what looks like a real `GITHUB_CLIENT_SECRET`. Blanked in the file (4921b63, 2026-10-30), but the old value stays in git history, so it still has to be rotated in GitHub. Don't copy the laptop `.env` to the server as-is either (it has commented-out provider tokens and a server password).
- [x] Production Horizon also ran the shared-defaults `supervisor-1` (auto-scaling to 10 processes) — fixed 2026-11-07: `defaults` is now empty, so production runs only its own four supervisors. `HorizonProductionConfigTest` guards this.
- [ ] Workers have no `stopwaitsecs` (Horizon recommends ≥ the longest job, 3600s). Since 2026-11-05 deploy and rollback restarts don't depend on it (they send SIGTERM and let the job finish), unless the worker has auto restart off. The Restart and Stop buttons, editing and deleting a worker, and "restart all" still force-kill after Supervisor's default 10 seconds. Raising it alone isn't safe: `supervisorctl stop`, `restart` and `update` then wait for the job, those calls have 30–60 second SSH limits, and if the connection drops mid-`restart` or `update` the worker stays stopped.
- [x] `horizon:terminate` waited for the longest running job (up to an hour for a backup), stalling every queue meanwhile — fixed 2026-11-07: `fast_termination` is on, so the new Horizon starts right away while the old workers finish their jobs.
- [x] Editing a site's environment only ran `queue:restart`, so Horizon's master and Reverb kept the old values — fixed 2026-11-07: "Restart queue workers" now runs `postActivationScript()` from the live release (and nothing for non-Laravel sites, which have no artisan).
- [x] `failed_jobs` and the single `laravel.log` grew forever — fixed 2026-11-07: `queue:prune-failed --hours=168` runs daily (the same 7 days Horizon keeps failed jobs). The log is covered by `LOG_STACK=daily` in cutover step 3.
- [x] 10 tests were already failing before 2026-10-13 (fixed 2026-10-13): `NginxConfigServiceTest` (×4, PHP-FPM socket path), `SiteManagementTest` env page (deferred prop), `PhpRuntimeResolverTest` and `KeyGeneratorTest` (unit tests not booting the app). The full suite still needs `memory_limit` above 128M: run `php -d memory_limit=1G vendor/bin/pest` (`artisan test` doesn't pass `-d` on to Pest).

## Features to work on next (added 2026-10-30)
- [ ] **AWS as a server provider** — code done 2026-10-30 (`AwsProvider`: root login via cloud-init, shared `flitops` security group per region, Elastic IP per server released on delete, region-prefixed IDs, curated instance sizes). **Still to do:** one real run on an AWS account — create a `t3.small`, let it provision, delete it, and confirm no instance, Elastic IP or key pair is left behind. As of 2026-11-07 no AWS server has been created.

## Security hardening: what has run on real servers (checked 2026-11-07)
From the FlitOps database: 4 isolated sites (Bouclay included) with 30 successful deploys, 29 completed backups since the secrets-off-the-command-line change, and 1 SSH key synced to a site user. Not yet seen for real: a restore. Converting an existing site with "Isolate this site" was tested by the user and works (confirmed 2026-11-08).

## Full audit (2026-11-11)
A read of the whole backend before opening FlitOps to other teams. Not covered in depth: the React frontend, the four cloud provider clients, the internals of converting a site to isolation, PHP and Node version management, and the source-control login flow. Every test fakes the SSH connection, so none of the fixes below has run on a real server.

### Fixed (merged to `main` on 2026-11-11)
- [x] A remote command that ran out of time counted as a success (de7e847). The SSH library keeps the previous command's exit code when the current one reports none, so this affected every command, not only provisioning. A timed-out command now fails with "timed out", and its channel is closed so later commands can run.
- [x] Provisioning always opened port 22 in the firewall, locking out a custom server that takes SSH on another port (8b5a28d). It now opens the port FlitOps connects on.
- [x] A Developer could reach root (e732100). The server user can sudo, and a site that isn't isolated runs as that user. Choosing what runs as the server user now needs `AccessServersAsAdmin`: the deploy script, environment file, commands, repository, branch and auto-deploy on a site that isn't isolated; creating a site without isolation; and workers and cron jobs that aren't tied to an isolated site. Developers can still deploy, cancel and roll back those sites.
- [x] Custom web server config was another way to root for Developers, on isolated sites too, because a config file can hand a request to the shared PHP process that runs as the server user (cf838e5). Writing these files now needs `AccessServersAsAdmin`. Reading them is unchanged.
- [x] Two changes to one server's web server config could run at once, each failing on the other's half-written file (742515f). Nine places that write config now take a lock per server (`withConfigLock`); a change waits 20 seconds for one already running, then fails saying so.
- [x] A web server sync that failed told nobody (521abff). `SyncSiteNginxJob` now marks the site's main configs as failed with the error, which the config page shows, and emails the server's owner.
- [x] Backups shared two production queue workers with the provisioning queue (9d9c2ff). They have their own supervisor now.

### Follow-ups to those fixes
- [ ] Hide the buttons Developers can no longer use (deploy script, environment, commands, repository, config editor, server-level workers and cron). They are refused on the server but still shown. Waits for the dashboard redesign to merge, since it touches `resources/`.
- [ ] Show "config not applied" on the domain page and site overview, not only on the web server config page and by email.
- [ ] `MoveSitePhpPoolJob` (PHP version change on an isolated site) still fails silently.
- [ ] A command the server kills (for example for using too much memory) reports no exit code, so after an earlier success on the same connection it can still look successful. The SSH library gives no way to clear the old code.
- [ ] A timed-out command may keep running on the server after FlitOps gives up on it.
- [ ] Wildcard certificate distribution takes the config lock but has no lock test.

### Open, each needs a decision first
The open items below come from reading the code. The first four in this list were re-read on 2026-11-11 while fixing the items above; re-check the rest against the code before fixing them.

- [ ] **Sharing Redis breaks the host server's own sites.** The first connection from another server puts a password on Redis and restarts it (`AllowServerConnection`), but the host's own sites have no `REDIS_PASSWORD`. Decide: warn and list the affected sites before connecting, or write the password into their environment files. No server connections exist today.
- [ ] **New servers are not hardened for SSH.** Provisioning leaves password login and root login on, installs nothing to block repeated login attempts, and gives the server user a password. Decide how far to go, since turning off password login can lock out someone who relies on it.
- [ ] **Anyone can claim a domain they don't own.** Hostnames are unique across all teams and adding one needs no proof (`SiteDomainController::store`), and no free subdomain names are reserved (`www`, `login`). Decide how a hostname is held before it is verified, and which names to reserve.
- [ ] **Two domains can share one config file name.** Dots become dashes, so `a-b.com` and `a.b.com` both become `a-b-com.conf` and the second replaces the first on the same server. Renaming the files touches every live site's config, so plan it with a resync.
- [ ] **Outdated packages with published advisories.** 12 PHP advisories across 7 Symfony packages; several high and critical JavaScript ones (`socket.io-parser`, `ws`, `dompurify`, `vite`). `composer update` and `npm audit fix` change dependencies, and `package.json` is also changed by the redesign branch.
- [ ] **Nothing has been proven on a real server.** A repeatable check on a throwaway server: create a site, add a domain, turn on HTTPS, change the www redirect, switch PHP version, isolate, delete, on both web servers. Still the biggest gap.

### Open, smaller
- [ ] Before running the web server resync with `--all`: the database showed 15 main configs across 9 sites and no "Before", "Locations" or other custom files, so it has nothing to delete. Hand edits made directly on a server would not show in the database.
- [ ] If a rejected sync touches a config file FlitOps has no saved copy of, the rollback deletes that file instead of restoring it (`BaseWebServer::restoreSiteConfigs`).
- [ ] `APP_URL` is always written as `https://`, even for a domain with no certificate yet.
- [ ] A wildcard custom domain's certificate covers only the domain and `www`, so its subdomains get no valid HTTPS.
- [ ] A history entry for a main config can still be restored, although main configs are read-only (`SiteNginxController::restore`).
- [ ] Team invitations never expire, and the email match is case-sensitive.
- [ ] An open browser terminal stays open for up to 2 hours after the person is removed from the team.
- [ ] Deploys are capped at 10 minutes with no setting to raise it.
- [ ] No rate limits on the webhook or on expensive actions such as creating servers.
- [ ] Database and Redis traffic between connected servers is unencrypted when it crosses the public internet.
- [ ] When the shared wildcard certificate first becomes available, existing free-domain sites stay on HTTP until something else triggers a sync.
- [ ] About 15 places write files to servers through the shell's `echo`. The server user's shell is bash, whose `echo` leaves backslashes alone, so this is tidiness, not urgent; the database and backup code already upload to a private folder and install from there.
- [ ] Tables, routes and classes say "nginx" for things that now serve both web servers.
- [ ] The local database still lists the migration names from before the consolidation, so `php artisan migrate:status` shows 41 as pending although every table exists. Mark them as run before running any new migration there.

### Checked and sound
Every route has a permission check and IDs sent in forms are limited to the team. Backups and restores keep secrets off the command line, and foreign dumps load with client commands switched off. Browser terminal tokens are single-use and expire in 60 seconds, the host key is pinned, and the private key never reaches the browser. Webhook signatures are checked, two deployments of one site cannot run at once, and stored secrets are encrypted in the database.

## How to use this
Work top to bottom — item 0 (Control Plane Resilience) undermines everything below it, so it's the actual current bottleneck regardless of what looks more urgent by itself. As of 2026-11-07 the code side of item 0 is ready; what's left is carrying out the cutover steps (re-checked that day for isolated sites). The custom-domain renewal gap (item 8) and broken rollback (item 3) were fixed the same day; existing custom domains still need "Renew SSL" clicked once to get the renewal hook. After that, items 5 and 9 (audit trail, log access) are the largest remaining gaps. Item 12 stays last; it's the most deferrable by a wide margin.
