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

**Where each command runs.** Every command block in this guide is labelled with one of these three places. Run it only there.

| Label | Where that is | How you get there |
|---|---|---|
| **[Laptop]** | A terminal on your Mac, inside the project folder | `cd /Users/admin/software-projects/cremir-inc/flitops` |
| **[Server, as `artisan`]** | The new server, logged in as the admin user (it can use `sudo`) | From the laptop: `ssh artisan@SERVER_IP` |
| **[Server, as the site user]** | The new server, as the user that owns the FlitOps site, inside the live release folder | First get to **[Server, as `artisan`]**, then run the two commands below |

To become the site user and go to the live release:

```bash
sudo -iu flitops_com
```

```bash
cd ~/flitops.com/current
```

To go back from the site user to `artisan`, type `exit`.

If `ssh artisan@SERVER_IP` answers "Permission denied (publickey)", your laptop's public key isn't on the server yet. Add it in FlitOps under **SSH Keys** (left sidebar, under Integrations), then try again.

**Where things are in the FlitOps screens.** The left sidebar has **Servers**. Click a server to open it; its tabs are Overview, Sites, Databases, Connections, PHP, Processes, Runtime, Terminal, Settings. Click a site (on the server's **Sites** tab) to open it; its tabs are Overview, Deployments, Commands, Processes, Environment, Database, Deploy Script, Web Server, Domains, Logs. The steps below name the tab to use.

---

## Part A — Before the night (on the laptop)

### A1. Make sure the code and the laptop database agree
**The rule:** every migration (database change) that has run on the laptop must be committed and pushed, so production gets it too.

**Why it matters:** the data copy carries every column the laptop has. If production's code is missing a migration, loading the data fails with an error like "column … does not exist".

**Where it stands (checked 2026-11-12):** nothing to fix. The framework-detection work is committed. The newest migration that has run on the laptop is `2026_11_12_075452_add_app_directory_to_sites_table`, and it is committed.

Check it again on the day. **[Laptop]** This must print nothing. If it prints a file name, that migration isn't committed yet: commit it before A2.

```bash
git status --short database/migrations
```

If you create and run another migration before the night, commit and push it too. Step C5 checks this rule again on the night.

### A2. Commit what you want to ship, then push `main`
Production deploys from GitHub, so it only gets what has been pushed. Two things are not on GitHub yet (checked 2026-11-12):

1. **28 commits** on `main` that haven't been pushed.
2. **The server transfer feature**, which isn't committed at all. It has no migration, so it can't break the data copy. Either commit it before pushing (it then ships with the first deploy), or leave it uncommitted (production simply won't have it yet).

**[Laptop]** See what isn't committed:

```bash
git status --short
```

**[Laptop]** Run the tests before pushing. All of them should pass (1,970 passed on 2026-11-12):

```bash
php artisan test --compact
```

**[Laptop]** Push:

```bash
git push origin main
```

**[Laptop]** Confirm nothing is left behind. This must print `0`:

```bash
git rev-list --count origin/main..main
```

### A3. Gather what production needs
Have these ready before you start Part B:
- **The laptop's `APP_KEY`.** Production must use exactly this value, or it can't decrypt any saved secret (SSH keys, GitHub token, database passwords, site `.env` files). **[Laptop]** This prints the line to copy, including the `base64:` part:
  ```bash
  grep '^APP_KEY=' .env
  ```
- **Four more values from the laptop `.env`** that production reuses unchanged: `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ZONE_ID`, `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`. **[Laptop]** This prints them:
  ```bash
  grep -E '^(CLOUDFLARE_API_TOKEN|CLOUDFLARE_ZONE_ID|GITHUB_CLIENT_ID|GITHUB_CLIENT_SECRET)=' .env
  ```
- **SMTP details** (the host, port, username and password of a service that sends email) for sending from `flitops.com`, e.g. Postmark, Resend or Mailgun. The laptop uses Mailtrap, a test inbox on port 2525 that never delivers real email, so it can't be used in production.
- **A heartbeat monitor URL** from Healthchecks.io or Better Stack. Create a check with a 5-minute period and a 10-minute grace time, and copy its "ping URL". FlitOps calls that URL every 5 minutes; if the calls stop, the monitor emails you. Without it, a dead queue or scheduler goes unnoticed.
- **Access to the GitHub OAuth app** whose client ID is in the laptop `.env` (GitHub → your profile picture → Settings → Developer settings → OAuth Apps). You'll change its callback URL in step E3.
- **Access to `flitops.com`'s DNS** (the place where you set which server the address points to).

### A4. Lower the DNS TTL
A day before, open `flitops.com`'s DNS settings and set the TTL on its A record to 5 minutes (300 seconds). The TTL is how long other computers remember the old answer; a short one means a change takes effect quickly. If there's no A record for `flitops.com` yet, skip this: you create it in B3.

---

## Part B — Build the production server (using the laptop FlitOps)

Everything in Part B is done in the laptop FlitOps (`https://flitops.test`), with `composer run dev` running, unless a step says otherwise.

### B1. Add the server
First rent the server from your hosting company: a fresh **Ubuntu 24.04** machine with at least **2 vCPU and 4 GB RAM** (production Horizon runs about 14 worker processes). Note its public IP; that is `SERVER_IP`. You need to be able to log in to it as `root`.

Then, in FlitOps:

1. Left sidebar → **Servers** → **Create server**.
2. Under "How to add the server", choose **Existing server**.
3. **Connection:** type `SERVER_IP` into **IP address**. Leave **SSH port** at 22 unless your host uses another.
4. **Name:** anything you'll recognise, e.g. `flitops-production`.
5. **Server type:** choose **App Server**, then set the boxes below it to: **PHP** = PHP 8.4, **Web server** = Nginx, **Database** = PostgreSQL, **Redis** = on.
6. Click **Connect server**. FlitOps opens a setup page with three numbered steps.
7. **Step 1, "Add the FlitOps key":** copy the command shown. Log in to the server as root (`ssh root@SERVER_IP` from the laptop) and paste it there. It lets FlitOps log in over SSH.
8. **Step 2, "Test the connection":** click **Test connection**. Wait for the green success message. If it's red, the command in step 7 didn't run properly; run it again.
9. **Step 3:** click **Start provisioning**. FlitOps now installs everything and shows progress. Wait until the server's status says it is ready (roughly 10–15 minutes). Don't go on to B2 before that.

Node.js (needed to build the JavaScript) is installed automatically; there is nothing to choose for it.

### B2. Create the database
Open the server → **Databases** tab.

1. Click **New database**. **Name:** `flitops`. Leave the other fields as they are. Click **Create database**.
2. Click **New user**. **Username:** `flitops`. Click **Generate password**. **Permission:** **Read-write**. If the form asks which database the user can use, pick `flitops`. Click **Create user**.
3. In the Database users list, click **Connect** on the `flitops` user. In the dialog, click the reveal (eye) button next to **Password** and copy the password. You need it in B4.

Don't create the database by hand on the server with `createdb`. FlitOps's version also gives the user the permissions the app needs.

### B3. Create the site — don't deploy yet
Open the server → **Sites** tab → **Add site**. Fill the form in like this:

- **Repository:** pick your GitHub account, then repository `josephajibodu/dpanel`, **Branch** `main`.
- **Framework:** **Laravel** (it's normally filled in for you once the repository is chosen).
- **App folder:** leave empty. The app is at the top of the repository.
- **PHP version:** 8.4.
- **Frontend build:** `npm`, with the command `npm run build`.
- **Domain:** set **Domain type** to **Custom** and type `flitops.com`.
- **Isolate this site:** **on**. This gives the site its own Linux user instead of sharing one with other sites.
- **Database:** leave this section alone; don't connect or create a database here. You made it in B2 and give the site its details in B4.

Click **Create site** and wait until the site finishes being created.

Then:

1. **Check the site user.** On the site's **Overview** tab, read the **System user** value. This guide assumes `flitops_com`. If yours is different, use your value everywhere this guide says `flitops_com`.
2. **Point the address at the server.** In `flitops.com`'s DNS settings, create (or change) the **A record** for `flitops.com` so its value is `SERVER_IP`. If DNS is on Cloudflare, set the record to **DNS only** (grey cloud) for now; the proxy (orange cloud) gets in the way of issuing the certificate and of live updates.
3. **Verify the domain and get HTTPS.** Open the site → **Domains** tab. Next to `flitops.com`, click **Verify**. FlitOps checks that the address really points at this server, and as soon as that passes it requests the HTTPS certificate by itself. You're done when the row shows **Verified** and "SSL · expires …". If Verify fails, DNS hasn't caught up yet: wait a few minutes and click it again. If it's verified but no SSL line appears after a couple of minutes, open the row's menu (⋯) and click **Renew SSL**.

**Don't click Deploy yet.** The environment in B4 has to be saved first.

### B4. Fill in the environment (before the first deploy)
The build bakes the `VITE_*` values into the JavaScript, so they must be right before deploying.

Open the site → **Environment** tab. Click **Reveal secrets** if the editor is hidden. Replace whatever is in the editor with the block below, fill in every `<…>` part (remove the `<` and `>` too), then click **Save & Sync**.

For the three Reverb values, make up fresh ones. **[Laptop]** Run this three times; use any number (e.g. `100001`) for `REVERB_APP_ID`, and one output each for `REVERB_APP_KEY` and `REVERB_APP_SECRET`:

```bash
openssl rand -hex 16
```

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

# Live updates (Reverb). Fresh values from the openssl command above.
# Leave the VITE_REVERB_APP_KEY line exactly as written: it copies REVERB_APP_KEY.
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

Lines that say `127.0.0.1` are correct as written. That address means "this same machine": the database, Redis and Reverb all run on the same server as FlitOps.

If your site user isn't `flitops_com`, change it in the `ACME_BINARY` line too.

### B5. Deploy
Open the site → **Deployments** tab → **Deploy Now**. Wait until the deployment's status shows it finished successfully (a few minutes).

Then open `https://flitops.com/up` in your browser. It must show a page saying the application is up. **[Laptop]** Or check from the terminal; this must print `200`:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://flitops.com/up
```

This first deploy also runs the migrations, which builds production's tables from the migration files. That database stays empty until the cutover. **Don't sign up or create anything on `flitops.com` yet**: whatever is in it gets wiped at cutover (C6).

If the deployment fails, click **View** on it to read the log. If it failed on `npm run build`, check that PHP works for the site user: **[Server, as the site user]** `php -v` should print a PHP 8.4 version. The build runs `php artisan wayfinder:generate`, so it needs PHP.

### B6. Live-updates proxy (Reverb)
Reverb is the small server that pushes live updates to the browser. It listens on port 8080 on the server only. This step tells Nginx (the web server) to pass browser connections on `/app/` to it.

Open the site → **Web Server** tab → **New file**.

- **Filename:** `reverb.conf`
- **Where it goes:** **Inside the site block**
- **Content:** paste this:

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

Click **Create**. The file's status should change to **Applied**. If it says **Failed**, the pasted text has a typo; fix it and save again.

### B7. Workers
A worker is a program the server keeps running all the time. FlitOps needs two: Horizon (runs background jobs such as deploys and backups) and Reverb (live updates).

Open the site → **Processes** tab. In the **Workers** card, click **New worker** and create these two, one at a time:

| Field | Worker 1 | Worker 2 |
|---|---|---|
| **Name** | `horizon` | `reverb` |
| **Command** | `php8.4 artisan horizon` | `php8.4 artisan reverb:start --host=127.0.0.1 --port=8080` |
| **User** | leave blank | leave blank |
| **Numprocs** | `1` | `1` |
| **Auto start** | ticked | ticked |
| **Auto restart** | ticked | ticked |
| **Restart after each deployment** | **not** ticked | **not** ticked |

Click **Create worker** after each. Both should show as running in the Workers list.

"Restart after each deployment" stays off because FlitOps's own deploy already restarts Horizon and Reverb; ticking it would restart them twice.

### B8. Scheduler
The scheduler is what runs timed work (backups, uptime checks, certificate renewal). It needs one cron job, a command the server runs on a timer.

Same **Processes** tab, in the **Cron jobs** card, click **New cron job**:

- **Command:** `php8.4 artisan schedule:run`
- **Frequency:** **Every minute**
- **User:** leave blank

Click **Create cron job**. It should appear in the list as enabled.

### B9. acme.sh (renews the `*.flitops.xyz` certificate)
acme.sh is the tool that requests the shared `*.flitops.xyz` certificate. It has to be installed for the site user, because FlitOps runs as that user.

**[Server, as `artisan`]** Replace `<your email>` with your address (certificate expiry warnings go there):

```bash
sudo -u flitops_com -H bash -c 'cd ~ && curl https://get.acme.sh | sh -s email=<your email>'
```

**[Server, as `artisan`]** Check it installed. This must print the path back, not "No such file". It is the same path as `ACME_BINARY` in B4:

```bash
sudo ls /home/flitops_com/.acme.sh/acme.sh
```

### B10. Check the empty production is healthy
**[Server, as the site user]** Nothing in the output should say Pending:

```bash
php8.4 artisan migrate:status | tail -3
```

**[Server, as the site user]** Should say Horizon is running:

```bash
php8.4 artisan horizon:status
```

**[Server, as the site user]** Prints the PostgreSQL version, most likely 16:

```bash
psql --version
```

**[Server, as `artisan`]** Both workers (horizon and reverb) should show `RUNNING`:

```bash
sudo supervisorctl status
```

Part B is done. Production is now a working, empty FlitOps. You can stop here and do Part C on another day.

---

## Part C — Cutover night (about 30–45 minutes)

Do the steps in order, C1 to C10, without skipping. From here until C10, don't use either FlitOps for real work (no deploys, no new sites, no settings changes): anything done on the laptop after C4 is not copied, and anything done on production before C6 is wiped.

Open two terminal windows before you start: one on the laptop in the project folder, and one logged in to the server (`ssh artisan@SERVER_IP`).

### C1. Let the laptop finish its work
Open `https://flitops.test/horizon` in the browser. On the dashboard, wait until **Jobs Past Hour** has stopped climbing and the **Pending Jobs** page (left menu) is empty. Jobs still waiting on the laptop are not copied, so a half-finished deploy or backup would be lost.

### C2. Switch the laptop off
**[Laptop]** Put the laptop FlitOps into maintenance mode, so its pages stop working:

```bash
php artisan down
```

Go to the terminal where `composer run dev` is running and press **Ctrl+C** to stop it. That stops the laptop's background jobs, scheduler and live updates.

**[Laptop]** Check nothing is left running. This must print nothing. If it prints a line, the number at its start is a process ID; stop it with `kill <that number>` and run the check again:

```bash
pgrep -fl "artisan (horizon|queue|schedule|reverb)"
```

### C3. Pause production
**[Server, as the site user]** Put production into maintenance mode, so nobody can use it while the data loads:

```bash
php8.4 artisan down
```

Then type `exit` to go back to `artisan`; C6 and C7 need that user.

### C4. Copy the data from the laptop
This step makes two files on the laptop (the data, then the certificate files) and sends both to the server.

**[Laptop]** Export the data into `~/flitops-data.sql`. It asks for the laptop database password; that is the `DB_PASSWORD` value in the laptop `.env`. It takes a few seconds and prints nothing when it works.

```bash
/Users/Shared/DBngin/postgresql/17.0/bin/pg_dump -h 127.0.0.1 -U postgres -d flitops --data-only --disable-triggers --no-owner --no-acl --exclude-table-data=migrations --exclude-table-data=cache --exclude-table-data=cache_locks --exclude-table-data=sessions --exclude-table-data=jobs --exclude-table-data=job_batches --exclude-table-data=failed_jobs --exclude-table-data=password_reset_tokens -f ~/flitops-data.sql
```

What this does:
- `--data-only` copies rows, not table structure. Production's structure comes from the migration files (built in B5), which keeps it exactly in line with the code.
- `--exclude-table-data` skips things that shouldn't move: the migrations list (production has its own correct one), cache, logins (everyone signs in again), queued and failed jobs, and password-reset links.
- `--disable-triggers` lets rows load in any order. Two tables point at each other (users ↔ teams), so a strict order isn't possible.

**[Laptop]** Remove one line from that file. The laptop runs PostgreSQL 17, which writes a setting that the server's PostgreSQL 16 doesn't understand. Run this whatever version the server has; it is harmless on 17.

```bash
sed -i '' '/^SET transaction_timeout/d' ~/flitops-data.sql
```

**[Laptop]** Check the export worked. The file should be at least a few hundred kilobytes, not 0:

```bash
ls -lh ~/flitops-data.sql
```

Next, the certificate files. The shared `*.flitops.xyz` certificate is three files in `storage/app/certificates` on the laptop: the certificate, its chain, and its private key. They're on disk, not in the database, and Git ignores that folder, so a deploy never carries them. **Don't commit them to get them across**: the private key must stay out of the repository. You copy them by hand instead.

**[Laptop]** Pack them into one file, `~/flitops-certs.tgz`. `--no-xattrs` leaves out Mac-only file labels, which would otherwise make the server print "Ignoring unknown extended header keyword" warnings when unpacking:

```bash
tar --no-xattrs -czf ~/flitops-certs.tgz -C storage/app certificates
```

**[Laptop]** Check the pack. It should list exactly `server.key`, `chain.pem` and `server.crt` under `certificates/_.flitops.xyz/`:

```bash
tar tzf ~/flitops-certs.tgz
```

**[Laptop]** Send the data file and the certificate pack to the server. Both land in the `artisan` user's home folder:

```bash
scp ~/flitops-data.sql ~/flitops-certs.tgz artisan@SERVER_IP:~
```

**[Server, as `artisan`]** Check both arrived. It should list both files:

```bash
ls -lh ~/flitops-data.sql ~/flitops-certs.tgz
```

### C5. Check the code matches the data
This confirms production has every database change the laptop has (the rule from A1), before any data is loaded.

**[Laptop]** Show the newest migration that has run on the laptop:

```bash
php artisan migrate:status | grep Ran | tail -1
```

As of 2026-11-12 that is `2026_11_12_075452_add_app_directory_to_sites_table`. The laptop also lists 41 migrations as Pending; ignore those (the "Notes" at the end explain why).

**[Server, as the site user]** Show the newest migration on production:

```bash
php8.4 artisan migrate:status | grep Ran | tail -1
```

Both commands must print **the same migration name**. Then type `exit` to go back to `artisan`.

If the names differ, **stop here**. Production is running older code than the laptop's database. Push the missing commits (A2), deploy production again (B5), and repeat this step. Don't load the data until they match.

### C6. Load the data into production
**[Server, as `artisan`]** (not the site user). First empty every table except the migrations list. This also removes the default team roles that the migration created, because the copy brings its own. It prints `DO` when it works.

```bash
sudo -u postgres psql -d flitops -v ON_ERROR_STOP=1 -c "DO \$\$ BEGIN EXECUTE (SELECT 'TRUNCATE ' || string_agg(format('%I', tablename), ', ') || ' RESTART IDENTITY CASCADE' FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'); END \$\$;"
```

**[Server, as `artisan`]** Then load the copy. `--single-transaction` means it either loads completely or not at all; it never stops halfway.

```bash
sudo -u postgres psql -d flitops -v ON_ERROR_STOP=1 --single-transaction -q < ~/flitops-data.sql
```

It should finish with no line containing `ERROR`. A few lines of numbers (`setval` results) are normal.

**If it prints an `ERROR`:** nothing was loaded, so production is still empty and nothing is broken. Read the error. "column … does not exist" means the code and data don't match (redo C5). After fixing the cause, run both C6 commands again from the top; repeating them is safe.

**[Server, as `artisan`]** Check the data is there. It should print your number of users (7 on 2026-11-12), not `0`:

```bash
sudo -u postgres psql -d flitops -t -c 'SELECT count(*) FROM users'
```

### C7. Put the certificate files in place
All four commands here are **[Server, as `artisan`]** (not the site user). They use the pack you sent in C4.

Unpack it into the site's `shared` storage folder. `shared` is the folder every release points at and no deploy replaces, so the files survive future deploys. A release folder would be deleted after a few deploys.

```bash
sudo tar xzf ~/flitops-certs.tgz -C /home/flitops_com/flitops.com/shared/storage/app
```

Make the site user the owner, because FlitOps runs as that user and has to read the files:

```bash
sudo chown -R flitops_com: /home/flitops_com/flitops.com/shared/storage/app/certificates
```

Stop every other user on the server from reading the private key:

```bash
sudo chmod -R go-rwx /home/flitops_com/flitops.com/shared/storage/app/certificates
```

Check the result. It should show `chain.pem`, `server.crt` and `server.key`, all owned by `flitops_com`:

```bash
sudo ls -l /home/flitops_com/flitops.com/shared/storage/app/certificates/_.flitops.xyz
```

### C8. Point the certificate record at the server's folder
The database has one record saying where the certificate files are. It came over with the data in C6, so it still names a folder on your Mac. This step rewrites it to the server's folder. Do it only after C6 and C7; before C6 the record isn't there yet.

Switch to the site user and go to the live release:

```bash
sudo -iu flitops_com
```

```bash
cd ~/flitops.com/current
```

**[Server, as the site user]** Rewrite the record and confirm the file is where it now says. Copy the whole line; it is one command:

```bash
php8.4 artisan tinker --execute '$c = App\Models\Certificate::first(); $p = (new App\Services\Certificates\WildcardCertificateIssuer)->pathsFor($c->domain); $c->update(["certificate_path" => $p["certificate"], "private_key_path" => $p["private_key"], "chain_path" => $p["chain"]]); echo $c->certificate_path, " exists: ", var_export(is_file($c->certificate_path), true), PHP_EOL;'
```

It must print a `/home/flitops_com/...` path followed by `exists: true`.

If it prints `exists: false`, the files aren't in the folder the record now names. Go back to the C7 check: the three files must be in `shared/storage/app/certificates/_.flitops.xyz`. Fix that, then run this command again; running it twice is safe.

Stay logged in as the site user for C9.

### C9. Switch production on
**[Server, as the site user]** Take production out of maintenance mode and restart Horizon:

```bash
php8.4 artisan up && php8.4 artisan horizon:terminate
```

`horizon:terminate` stops Horizon so it starts fresh with the real data. Supervisor (the program that keeps the workers running) brings it straight back within a few seconds.

Now open `https://flitops.com` in the browser and log in with your usual email and password. You should see your servers. Production is live from this moment.

### C10. Make sure the laptop can't run against real servers again
**Why it matters:** the laptop database still holds the real servers' SSH keys. If `composer run dev` started against it, two FlitOps would run backups, uptime checks and certificate renewals against the same servers.

The fix: rename the real database out of the way (kept as a safety copy) and give the laptop a fresh empty one with the old name. The laptop `.env` keeps working unchanged.

**[Laptop]** Rename and recreate. It asks for the laptop database password (`DB_PASSWORD` in `.env`). It prints `ALTER DATABASE` then `CREATE DATABASE`:

```bash
/Users/Shared/DBngin/postgresql/17.0/bin/psql -h 127.0.0.1 -U postgres -c 'ALTER DATABASE flitops RENAME TO flitops_pre_cutover' -c 'CREATE DATABASE flitops'
```

If it says the database "is being accessed by other users", something on the laptop is still connected to it: close any database app (TablePlus etc.), recheck C2, and run it again.

**[Laptop]** Build the tables in the new empty database:

```bash
php artisan migrate --force
```

**[Laptop]** Take the laptop out of maintenance mode, so local development works again later:

```bash
php artisan up
```

The laptop FlitOps is now an empty development copy with no servers in it. From here on, do all real work on `https://flitops.com`.

---

## Part D — Check everything works

Do these on production (`https://flitops.com`) right after C10, in order. Each item says what to do and what you should see. If an item fails, the line under it says what that points to.

1. **The site is up and you can log in.** Open `https://flitops.com/up`: it shows the "application is up" page. Then log in at `https://flitops.com`. Everyone has to log in again; passwords are unchanged.
   *If login fails with the right password:* the data didn't load (recheck C6).

2. **Everything came across.** Left sidebar → **Servers**: every server you had on the laptop is listed, plus the new production server. Open each server's **Sites** tab: all 9 sites are there in total.

3. **Saved secrets can be read.** Open any site → **Environment** tab → **Reveal secrets**. The site's `.env` text appears.
   *If you get an error instead:* `APP_KEY` on production doesn't match the laptop's. Fix it in the FlitOps site's Environment tab (B4), click **Save & Sync**, and retry.

4. **Live updates work.** Open `https://flitops.com/realtime/test`. Under **Connection**, both **Socket** and **Private auth** should show as connected. Click **Send diagnostic message**: a new line appears under **Incoming events** within a second, without reloading the page.
   *If Socket never connects:* recheck the Reverb worker (B7) and the `reverb.conf` file (B6). If you changed any `VITE_*` value, you must deploy again for it to take effect.

5. **FlitOps can reach each server over SSH.** On each server, open one of its sites → **Web Server** tab → **Refresh**. It says "Reading the files from the server…" and finishes without an error message.
   *If it reports the server isn't connected:* production can't log in to that server; recheck item 3 (the SSH keys are among the saved secrets).

6. **Background jobs are running.** Open `https://flitops.com/horizon`: the status at the top says **Active**. **[Server, as `artisan`]** Both workers show `RUNNING`:
   ```bash
   sudo supervisorctl status
   ```
   *If `/horizon` says you aren't allowed:* your login email isn't in `HORIZON_ADMINS` (B4).

7. **There is exactly one scheduler.** **[Server, as `artisan`]** The list should contain one FlitOps file for the `schedule:run` job you made in B8, and no second one:
   ```bash
   sudo ls /etc/cron.d/
   ```

8. **The heartbeat arrives.** Within 10 minutes of C9, your monitor (Healthchecks.io or Better Stack) shows the check as up/green.
   *If it stays grey or red:* the scheduler isn't running (recheck B8) or `SCHEDULER_HEARTBEAT_URL` is wrong (B4).

9. **Backups work.** Open a server → **Databases** tab → a database's **Actions** menu → **Backups**, and start a backup now. Wait for it to show as completed.

10. **Certificate renewal works.** This is the one thing that otherwise stays untested for about 60 days. The first command makes FlitOps think the certificate has no expiry date, so the second one agrees to issue a fresh one. Both are **[Server, as the site user]**:
    ```bash
    php8.4 artisan tinker --execute 'App\Models\Certificate::query()->update(["expires_at" => null]);'
    ```
    ```bash
    php8.4 artisan flitops:wildcard:issue
    ```
    It should end with "Done." It requests a fresh `*.flitops.xyz` certificate and sends it to every server. It can take a couple of minutes.

    *If it fails:* nothing is broken, because the certificate you copied in C7 is still in place and valid until 20 January 2027. Run this to put the expiry date back, then fix acme.sh (B9) or the Cloudflare token (B4) and try again another day:
    ```bash
    php8.4 artisan tinker --execute '$c = App\Models\Certificate::first(); $c->update(["expires_at" => \Carbon\Carbon::createFromTimestamp(openssl_x509_parse(file_get_contents($c->certificate_path))["validTo_time_t"])]);'
    ```

11. **A new free-domain site gets HTTPS.** On any server → **Sites** → **Add site**, with **Domain type** = **Free** and any subdomain. When it's created, open its address in the browser: it loads over `https://` with no certificate warning. Then delete the site (site → Overview → **Delete Site**).

12. **FlitOps can deploy itself.** Open the FlitOps site (`flitops.com`) → **Deployments** → **Deploy Now**. It finishes successfully, the end of its log shows the queue, Horizon and Reverb being restarted, and `https://flitops.com` keeps working throughout.

13. **Email is delivered.** Left sidebar → **Settings** → **Team**, invite an email address of your own, and check the email arrives in a real inbox.
    *If it doesn't arrive:* the `MAIL_*` values (B4) are wrong, or your email provider hasn't approved `flitops.com` as a sender yet.

---

## Part E — Clean up (the next day or two)

1. **Delete the copy files.** They contain your secrets (encrypted, but still) and the certificate's private key. Do this once Part D has passed.

   **[Laptop]**
   ```bash
   rm ~/flitops-data.sql ~/flitops-certs.tgz
   ```
   **[Server, as `artisan`]**
   ```bash
   rm ~/flitops-data.sql ~/flitops-certs.tgz
   ```
   Don't delete `storage/app/certificates` in the laptop project folder yet; keep it for the same week as the old database (item 6).
2. **DNS:** set the TTL on `flitops.com`'s A record back to its normal value (Auto, or 1 hour). If you want Cloudflare's proxy (orange cloud), switch it on now and redo checks D1, D4 and D10.
3. **GitHub OAuth app:** on GitHub, go to your profile picture → Settings → Developer settings → OAuth Apps → the FlitOps app, and change **Authorization callback URL** to exactly `https://flitops.com/auth/github/callback`, then save. Until you do this, everything works except connecting a *new* GitHub account. Keep using this same app in production, and **don't delete it**: your connected GitHub account's token belongs to it. For local development, create a second OAuth app later with the callback `https://flitops.test/auth/github/callback` and put its ID and secret in the laptop `.env`.
4. **Webhooks:** none to update. No site has deploy-on-push turned on. If you turn it on later, do it from production so GitHub is given the production address.
5. **Laptop `.env`:** your local FlitOps uses the same Cloudflare token and zone as production. Avoid creating free-domain sites locally, because they would add real DNS records to `flitops.xyz`.
6. **Old laptop database:** keep `flitops_pre_cutover` for a week as a safety copy, then delete it. **[Laptop]** It asks for the laptop database password:
   ```bash
   /Users/Shared/DBngin/postgresql/17.0/bin/dropdb -h 127.0.0.1 -U postgres flitops_pre_cutover
   ```
7. **Production FlitOps manages its own server.** Never delete or disconnect that server, or the FlitOps site, from inside FlitOps. Restarting PHP or Nginx on it from FlitOps briefly interrupts FlitOps itself.

---

## Part F — If something goes wrong

### F1. Going back to the laptop during the cutover
Until you start using production for real work, you can go back: nothing on the laptop has changed except maintenance mode (and, after C10, the database's name). Your hosted sites are not affected either way.

Decide this on the night. Once production has done real work (deploys, backups, new sites), going back to the laptop would lose that work.

1. **[Server, as the site user]** Switch production off, so only one FlitOps is running:
   ```bash
   php8.4 artisan down
   ```
2. **Only if you already did C10**, put the real database back under its old name first. **[Laptop]** This deletes the new empty database and renames the real one back. It asks for the laptop database password:
   ```bash
   /Users/Shared/DBngin/postgresql/17.0/bin/psql -h 127.0.0.1 -U postgres -c 'DROP DATABASE flitops' -c 'ALTER DATABASE flitops_pre_cutover RENAME TO flitops'
   ```
   If you stopped before C10, skip this.
3. **[Laptop]** Switch the laptop back on:
   ```bash
   php artisan up
   ```
   ```bash
   composer run dev
   ```
4. Open `https://flitops.test` and check your servers are listed.

To try the cutover again later, start again from C1. C6 empties production's tables first, so a second attempt is safe.

### F2. A later deploy of FlitOps breaks FlitOps
**If the FlitOps pages still load:** open the FlitOps site → **Deployments** tab. On the last deployment that worked, open its **Actions** menu → **Rollback**, then confirm with **Roll back**.

**If the pages don't load at all**, switch back by hand. Each deploy lives in its own folder under `releases`, and `current` is a pointer to the one in use; this points it back at the previous one.

**[Server, as `artisan`]** List the release folders, oldest first. The last one is the broken deploy; the one before it is the one to go back to:

```bash
sudo ls /home/flitops_com/flitops.com/releases
```

**[Server, as `artisan`]** Replace `<previous release folder>` with that folder's name, then run:

```bash
sudo -u flitops_com -H bash -c 'cd ~/flitops.com && ln -sfn releases/<previous release folder> current && cd current && php8.4 artisan horizon:terminate && php8.4 artisan reverb:restart'
```

Then open `https://flitops.com` to confirm it loads again.

---

## Notes: what was checked (2026-11-12)

- **Migration files vs. the laptop database:** I built a fresh database from the migration files and compared it with the laptop's. Every column, type, default, constraint, index and sequence matched (841 items, no differences).
- **Full rehearsal of C4–C6:** I loaded a data-only copy into a fresh migration-built database owned by a normal (non-admin) user, as production will be. It loaded with no errors. Every table had the same row count (except the deliberately skipped ones), there were no broken links between tables, and every ID counter was ahead of its table. With the laptop's `APP_KEY`, all 113 saved secrets decrypted. The rehearsal database was deleted afterwards.
- **Why the laptop shows 41 migrations as Pending:** its migrations list still has the names from before the migrations were combined (2026-11-08). The tables are all there and correct. Production gets the new names from its own first deploy, which is why C4 doesn't copy the list. **Don't run `php artisan migrate` on the laptop's real database**: it would try to create tables that already exist.
- **Laptop-only values in the data:** the only one that breaks anything is the wildcard certificate's file path (fixed in C8). Old notification links to `flitops.test` and old log text are harmless.
- **Files outside the database:** only `storage/app/certificates` (copied in C4/C7). SSH keys, tokens and passwords are all in the database.
- **Tests:** all 1,970 passed, none failed. This run included the uncommitted server transfer work.
- **What points at the laptop:** no laptop address is hard-coded in the code. The laptop-only settings (`APP_URL`, the Reverb hosts, Mailtrap on port 2525, `ACME_BINARY`, the terminal gateway URL) each get their own production value in B4. Encrypted values (saved site `.env` files, tokens) could not be searched.
- **Screen names:** the button, tab and field names in Parts B, D and F were checked against the app's code on this date. If a screen is redesigned later, the names may differ slightly.
