# Moving FlitOps from the laptop to production

A complete, in-order guide. Do every step; nothing here is optional unless it says so.

**What happens, in one paragraph.** You use the laptop FlitOps to set up a new server and a FlitOps site on it, and deploy it once. That first deploy builds an empty production database from the migration files. On the cutover night you switch the laptop off, copy the laptop's *data* (not its table structure) into that empty production database, and switch production on. Production then manages every server and site the laptop managed, including the server it runs on. Your hosted sites keep serving visitors the whole time; only FlitOps itself pauses.

**Names used below.** Replace them if yours differ:

| In this guide | Means |
|---|---|
| `flitops.com` | The address FlitOps will live at |
| `SERVER_IP` | The new server's public IP |
| `flitops_com` | The Linux user FlitOps creates for the site (shown on the site's page after you create it; check it) |
| `/home/flitops_com/flitops.com` | That site's folder (`current`, `releases`, `shared` live in it) |

You'll SSH in as `artisan` (the server user, which has `sudo`). To run the site's own commands, switch to the site user first:

```bash
sudo -iu flitops_com
cd ~/flitops.com/current
```

---

## Part A — Before the night (on the laptop)

### A1. Deal with the unfinished framework-detection work
Your working copy has uncommitted changes (framework detection on sites). Its migration `2026_11_11_221242_add_framework_to_sites_table` has **already run on your laptop database**, so the laptop's `sites` table has a `framework` column.

**Why it matters:** the data copy carries that column. If production runs code without that migration, loading the data fails with "column framework does not exist".

Do one of these:
- **Finish it, commit it, and push it** before the night (recommended). Two tests currently fail because of it: `tests/Feature/SiteControllerTest.php:126` and `tests/Feature/SiteManagementTest.php:50` both still expect the `projectTypes` page prop that the change removed. Update them or the code, then run `php artisan test --compact`.
- **Or set it aside**: undo it locally with `php artisan migrate:rollback --step=1` (removes the column), then stash or branch the code.

**Rule for the night:** the newest migration that has run on the laptop must also exist on production. Step C5 checks this.

### A2. Push `main`
`main` is 19 commits ahead of GitHub. Production deploys from GitHub, so push:

```bash
git push origin main
```

### A3. Gather what production needs
Have these ready before you start Part B:
- **The laptop's `APP_KEY`** (in `.env`). Production must use exactly this value, or it can't decrypt any saved secret (SSH keys, GitHub token, database passwords, site `.env` files).
- **SMTP details** for sending email from `flitops.com` (e.g. Postmark, Resend, Mailgun). The laptop uses a test mail server on port 2525, which won't work in production.
- **A heartbeat monitor URL** (Healthchecks.io or Better Stack): a check with a 5-minute period and 10-minute grace. FlitOps pings it every 5 minutes; if pings stop, it emails you. Without it, a dead queue or scheduler goes unnoticed.
- **Access to the GitHub OAuth app** whose client ID is in the laptop `.env`. You'll change its callback URL in step E3.
- **Access to `flitops.com`'s DNS.**

### A4. Lower the DNS TTL
A day before, set the TTL (how long other computers remember the old answer) on `flitops.com`'s DNS record to 5 minutes, so a change takes effect quickly.

---

## Part B — Build the production server (using the laptop FlitOps)

### B1. Add the server
Servers → add a **Custom** server: Ubuntu 24.04, at least 2 vCPU / 4 GB RAM (production Horizon runs about 14 worker processes). Choose **Nginx**, **PostgreSQL**, **Redis**, **PHP 8.4**, Node 22. Run the root-key command FlitOps shows on the server, then wait until provisioning finishes.

### B2. Create the database
On the server's Databases page: database `flitops`, and a database user `flitops` with **read-write** access to it. Copy the password (the user's "Connect" dialog shows it).

Don't create the database by hand with `createdb`. FlitOps's version also gives the user the permissions the app needs.

### B3. Create the site — don't deploy yet
- Type **Laravel**, domain `flitops.com`, repository `josephajibodu/dpanel`, branch `main`, isolation **on**.
- Note the site user on the site's page (expected `flitops_com`).
- Point `flitops.com`'s DNS A record at `SERVER_IP`. If DNS is on Cloudflare, use **DNS only** (grey cloud) for now, which makes certificate issuing and live updates simpler.
- On the site's Domains page, issue the SSL certificate.

### B4. Fill in the environment (before the first deploy)
The build bakes the `VITE_*` values into the JavaScript, so they must be right before deploying. Paste this into the site's Environment page and fill in the `<…>` parts:

```dotenv
APP_NAME=flitops
APP_ENV=production
APP_KEY=<the laptop's APP_KEY, exactly>
APP_DEBUG=false
APP_URL=https://flitops.com

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=flitops
DB_USERNAME=flitops
DB_PASSWORD=<from step B2>

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# No SSR server runs in production; say so explicitly.
INERTIA_SSR_ENABLED=false

# Live updates (Reverb). Make up fresh values: APP_ID any number,
# KEY and SECRET long random strings. VITE_REVERB_APP_KEY must equal REVERB_APP_KEY.
REVERB_APP_ID=<number>
REVERB_APP_KEY=<random>
REVERB_APP_SECRET=<random>
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGINS=flitops.com
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=flitops.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
VITE_REALTIME_DEBUG=false
VITE_APP_NAME="${APP_NAME}"

MAIL_MAILER=smtp
MAIL_HOST=<smtp host>
MAIL_PORT=<587 usually>
MAIL_USERNAME=<smtp user>
MAIL_PASSWORD=<smtp password>
MAIL_FROM_ADDRESS=<e.g. hello@flitops.com>
MAIL_FROM_NAME="${APP_NAME}"

# Who may open /horizon (comma-separated login emails); empty = nobody.
HORIZON_ADMINS=<your login email>
# Where platform alerts go (wildcard certificate failing, etc.).
ALERT_EMAIL=<your email>
SCHEDULER_HEARTBEAT_URL=<monitor ping URL from A3>

# Free *.flitops.xyz subdomains: same values as the laptop .env.
FREE_DOMAIN=flitops.xyz
CLOUDFLARE_API_TOKEN=<same as laptop>
CLOUDFLARE_ZONE_ID=<same as laptop>
ACME_BINARY=/home/flitops_com/.acme.sh/acme.sh

# GitHub login/connection: same app as the laptop (see step E3).
GITHUB_CLIENT_ID=<same as laptop>
GITHUB_CLIENT_SECRET=<same as laptop>

# Browser terminal stays off for now (README → "Browser terminal" → "Production" to turn it on later).
TERMINAL_GATEWAY_URL=
TERMINAL_GATEWAY_SECRET=
```

Leave these **out** on purpose:
- `SERVER_USER`: must stay `artisan`, matching every existing server.
- `SERVER_ALLOW_PRIVATE_ADDRESSES`
- `GITHUB_REDIRECT_URI`: the default works on any address.
- The laptop's `AWS_*` lines: nothing in the app reads them (AWS provider keys live in the database).

### B5. Deploy
Deploy the site from the laptop. When it finishes, `https://flitops.com/up` must return 200.

This first deploy also runs the migrations, which builds production's tables from the migration files. That database stays empty until the cutover. If anyone signs up there before then, it gets wiped at cutover.

If the deploy fails on `npm run build`, check that `php -v` works as the site user. The build runs `php artisan wayfinder:generate`.

### B6. Live-updates proxy (Reverb)
On the site's Nginx page, add a **Locations** file:

```nginx
location ^~ /app/ {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_pass http://127.0.0.1:8080;
}
```

### B7. Workers
Add these on the site's Workers page. Both are tied to the site, run 1 process, have auto start and auto restart **on**, and **restart on deploy off**. Every deploy already restarts them.
1. `php8.4 artisan horizon`
2. `php8.4 artisan reverb:start --host=127.0.0.1 --port=8080`

### B8. Scheduler
Add a cron job on the site, every minute: `php8.4 artisan schedule:run`.

### B9. acme.sh (renews the `*.flitops.xyz` certificate)
SSH in as `artisan`:

```bash
sudo -u flitops_com -H bash -c 'curl https://get.acme.sh | sh -s email=<your email>'
```

### B10. Check the empty production is healthy
As the site user in `current`:

```bash
php8.4 artisan migrate:status | tail -3
php8.4 artisan horizon:status
psql --version
```

Nothing should say Pending, Horizon should be running, and `psql` will most likely say 16. Also run `sudo supervisorctl status` as `artisan`: both workers should show RUNNING.

---

## Part C — Cutover night (about 30–45 minutes)

From here until C10, don't use either FlitOps for real work.

### C1. Let the laptop finish its work
Open `https://flitops.test/horizon` and wait until nothing is Pending or Running. Jobs waiting in the laptop's Redis are not copied.

### C2. Switch the laptop off

```bash
php artisan down
```

Stop `composer run dev` (Ctrl+C in its terminal), then check nothing is left running. This must print nothing:

```bash
pgrep -fl "artisan (horizon|queue|schedule|reverb)"
```

### C3. Pause production
SSH in as `artisan`, then:

```bash
sudo -iu flitops_com
cd ~/flitops.com/current
php8.4 artisan down
exit
```

### C4. Copy the data from the laptop
On the laptop, in the project folder. It asks for the laptop database password (`DB_PASSWORD` in `.env`).

```bash
/Users/Shared/DBngin/postgresql/17.0/bin/pg_dump -h 127.0.0.1 -U postgres -d flitops --data-only --disable-triggers --no-owner --no-acl --exclude-table-data=migrations --exclude-table-data=cache --exclude-table-data=cache_locks --exclude-table-data=sessions --exclude-table-data=jobs --exclude-table-data=job_batches --exclude-table-data=failed_jobs --exclude-table-data=password_reset_tokens -f ~/flitops-data.sql
```

What this does:
- `--data-only` copies rows, not table structure. Production's structure comes from the migration files (built in B5), which keeps it exactly in line with the code.
- `--exclude-table-data` skips things that shouldn't move: the migrations list (production has its own correct one), cache, logins (everyone signs in again), queued and failed jobs, and password-reset links.
- `--disable-triggers` lets rows load in any order. Two tables point at each other (users ↔ teams), so a strict order isn't possible.

Remove the one line that PostgreSQL 16 doesn't understand (your laptop runs 17):

```bash
sed -i '' '/^SET transaction_timeout/d' ~/flitops-data.sql
```

Pack the certificate files (they're on disk, not in the database), then send both files:

```bash
tar czf ~/flitops-certs.tgz -C storage/app certificates
```

```bash
scp ~/flitops-data.sql ~/flitops-certs.tgz artisan@SERVER_IP:~
```

### C5. Check the code matches the data
On the laptop, `php artisan migrate:status | tail -3` shows the newest migration that ran. Ignore the lines marked Pending; see "Notes" at the end for why they appear. On the server, as the site user in `current`, `php8.4 artisan migrate:status | tail -3` must show that **same migration as Ran**. If it doesn't, stop: deploy the right code first (see A1).

### C6. Load the data into production
On the server, as `artisan` (not the site user). First empty every table except the migrations list. This also removes the default team roles that the migration created, because the copy brings its own.

```bash
sudo -u postgres psql -d flitops -v ON_ERROR_STOP=1 -c "DO \$\$ BEGIN EXECUTE (SELECT 'TRUNCATE ' || string_agg(format('%I', tablename), ', ') || ' RESTART IDENTITY CASCADE' FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'); END \$\$;"
```

Then load the copy. `--single-transaction` means it either loads completely or not at all; it never stops halfway.

```bash
sudo -u postgres psql -d flitops -v ON_ERROR_STOP=1 --single-transaction -q < ~/flitops-data.sql
```

It should finish with no `ERROR` lines.

### C7. Put the certificate files in place

```bash
sudo tar xzf ~/flitops-certs.tgz -C /home/flitops_com/flitops.com/shared/storage/app
```

```bash
sudo chown -R flitops_com: /home/flitops_com/flitops.com/shared/storage/app/certificates
```

```bash
sudo chmod -R go-rwx /home/flitops_com/flitops.com/shared/storage/app/certificates
```

### C8. Point the certificate record at the server's folder
The copied record still points at a folder on your Mac. As the site user in `current`:

```bash
php8.4 artisan tinker --execute '$c = App\Models\Certificate::first(); $p = (new App\Services\Certificates\WildcardCertificateIssuer)->pathsFor($c->domain); $c->update(["certificate_path" => $p["certificate"], "private_key_path" => $p["private_key"], "chain_path" => $p["chain"]]); echo $c->certificate_path, " exists: ", var_export(is_file($c->certificate_path), true), PHP_EOL;'
```

It must print a `/home/flitops_com/...` path followed by `exists: true`.

### C9. Switch production on
As the site user in `current`:

```bash
php8.4 artisan up && php8.4 artisan horizon:terminate
```

`horizon:terminate` restarts Horizon so it starts fresh with the real data. Supervisor brings it straight back.

### C10. Make sure the laptop can't run against real servers again
On the laptop, rename the real database out of the way and give the laptop a fresh empty one. `.env` keeps working unchanged. It asks for the password.

```bash
/Users/Shared/DBngin/postgresql/17.0/bin/psql -h 127.0.0.1 -U postgres -c 'ALTER DATABASE flitops RENAME TO flitops_pre_cutover' -c 'CREATE DATABASE flitops'
```

```bash
php artisan migrate --force
```

**Why it matters:** if `composer run dev` started against the real data, two FlitOps would run backups, uptime checks and certificate renewals against the same servers.

---

## Part D — Check everything works

Go through every item:

1. `https://flitops.com/up` returns 200, and you can log in (everyone has to log in again; passwords are unchanged).
2. Both servers and all 9 sites are listed. Click **Test connection** on each server; it should succeed and update live without a page refresh, which proves live updates work.
3. Open a site's **Environment** page and confirm the `.env` shows. This proves `APP_KEY` is right.
4. `/horizon` opens and shows running supervisors. As `artisan`, `sudo supervisorctl status` shows both workers RUNNING.
5. Only one scheduler: `sudo ls /etc/cron.d/` shows a single FlitOps cron for `schedule:run`.
6. Within 10 minutes the heartbeat monitor goes green.
7. Run an on-demand backup of one database and see it complete.
8. **Prove certificate renewal works** (this is the one thing that otherwise stays untested for about 60 days). As the site user in `current`:
   ```bash
   php8.4 artisan tinker --execute 'App\Models\Certificate::query()->update(["expires_at" => null]);'
   ```
   ```bash
   php8.4 artisan flitops:wildcard:issue
   ```
   It should end with "Done." It issues a fresh `*.flitops.xyz` certificate and sends it to every server.

   **If it fails**, put the expiry back, then fix acme.sh or the Cloudflare token:
   ```bash
   php8.4 artisan tinker --execute '$c = App\Models\Certificate::first(); $c->update(["expires_at" => \Carbon\Carbon::createFromTimestamp(openssl_x509_parse(file_get_contents($c->certificate_path))["validTo_time_t"])]);'
   ```
9. Create a throwaway site on a free `*.flitops.xyz` subdomain, check it gets a working HTTPS certificate, then delete it.
10. Deploy FlitOps itself once from production (a "self-deploy"). The log should end with the queue, Horizon and Reverb restart lines, and the site stays up.
11. Send a team invite to yourself and check the email arrives.

---

## Part E — Clean up (the next day or two)

1. **Delete the copy files.** They contain your secrets (encrypted, but still). On the laptop:
   ```bash
   rm ~/flitops-data.sql ~/flitops-certs.tgz
   ```
   On the server, as `artisan`:
   ```bash
   rm ~/flitops-data.sql ~/flitops-certs.tgz
   ```
2. **DNS:** set the TTL back to normal. If you want Cloudflare's proxy (orange cloud), switch it on now and recheck D1, D2 and D8.
3. **GitHub OAuth app:** change its callback URL to `https://flitops.com/auth/github/callback`. Keep using this same app in production, and **don't delete it**: your connected GitHub account's token belongs to it. For local development, create a second OAuth app later with the `flitops.test` callback and put its ID and secret in the laptop `.env`.
4. **Webhooks:** none to update. No site has deploy-on-push turned on. If you turn it on later, do it from production so GitHub gets the production address.
5. **Laptop `.env`:** your local FlitOps uses the same Cloudflare token and zone as production. Avoid creating free-domain sites locally, because they would add real DNS records to `flitops.xyz`.
6. **Old laptop database:** keep `flitops_pre_cutover` for a week as a safety copy, then drop it:
   ```bash
   /Users/Shared/DBngin/postgresql/17.0/bin/dropdb -h 127.0.0.1 -U postgres flitops_pre_cutover
   ```
7. **Production FlitOps manages its own server.** Never delete or disconnect that server, or the FlitOps site, from inside FlitOps. Restarting PHP or Nginx on it from FlitOps briefly interrupts FlitOps itself.

---

## Part F — If something goes wrong

**During the cutover (before you start using production):** nothing on the laptop has changed except maintenance mode, so you can go back.
1. On the server, as the site user in `current`: `php8.4 artisan down`.
2. On the laptop: `php artisan up` and `composer run dev`.
3. If you already did C10, first rename the databases back: drop the new empty `flitops`, then rename `flitops_pre_cutover` to `flitops`.

Decide this on the night. Once production has done real work (deploys, backups), going back to the laptop would lose that work.

**A later self-deploy breaks FlitOps:** open the last good deployment and click **Rollback to this**. If the web page itself is down, do it by hand as `artisan`:

```bash
sudo -u flitops_com -H bash -c 'cd ~/flitops.com && ln -sfn releases/<previous release folder> current && cd current && php8.4 artisan horizon:terminate && php8.4 artisan reverb:restart'
```

---

## Notes: what was checked (2026-11-12)

- **Migration files vs. the laptop database:** I built a fresh database from the migration files and compared it with the laptop's. Every column, type, default, constraint, index and sequence matched (841 items, no differences).
- **Full rehearsal of C4–C6:** I loaded a data-only copy into a fresh migration-built database owned by a normal (non-admin) user, as production will be. It loaded with no errors. Every table had the same row count (except the deliberately skipped ones), there were no broken links between tables, and every ID counter was ahead of its table. With the laptop's `APP_KEY`, all 113 saved secrets decrypted. The rehearsal database was deleted afterwards.
- **Why the laptop shows 41 migrations as Pending:** its migrations list still has the names from before the migrations were combined (2026-11-08). The tables are all there and correct. Production gets the new names from its own first deploy, which is why C4 doesn't copy the list. **Don't run `php artisan migrate` on the laptop's real database**: it would try to create tables that already exist.
- **Laptop-only values in the data:** the only one that breaks anything is the wildcard certificate's file path (fixed in C8). Old notification links to `flitops.test` and old log text are harmless.
- **Files outside the database:** only `storage/app/certificates` (copied in C4/C7). SSH keys, tokens and passwords are all in the database.
- **Tests:** 1,924 passed, 2 failed, and both failures come from the uncommitted framework-detection work (A1). TypeScript type check and production build both pass.
