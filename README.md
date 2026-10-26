# ServerForge - Laravel Forge MVP Clone

A server management and application deployment platform inspired by [Laravel Forge](https://forge.laravel.com/), built with Laravel 12, Inertia v2, React, and Shadcn UI.

See **[PRODUCTION_CHECKLIST.md](PRODUCTION_CHECKLIST.md)** for a checklist of features thay must be completed before full production use. 

## 📋 Documentation

| Document | Purpose |
|----------|---------|
| **[IMPLEMENTATION_PLAN.md](./IMPLEMENTATION_PLAN.md)** | 📍 **Start here!** Sequential task list with checkboxes |
| [TECHNICAL_PLAN.md](./TECHNICAL_PLAN.md) | Product overview, feature scope, system architecture, database schema |
| [TECHNICAL_PLAN_PART2.md](./TECHNICAL_PLAN_PART2.md) | Core workflows, jobs & queues |
| [TECHNICAL_PLAN_PART3.md](./TECHNICAL_PLAN_PART3.md) | SSH architecture, controllers/services/actions, API endpoints |
| [TECHNICAL_PLAN_PART4.md](./TECHNICAL_PLAN_PART4.md) | UI screens, realtime features, security, testing, roadmap |

### How to Use These Docs

1. **Follow `IMPLEMENTATION_PLAN.md`** for step-by-step development order
2. **Reference `TECHNICAL_PLAN*.md`** for detailed specifications when building each feature

## 🎯 MVP Feature Summary

### Included in MVP ✅

- **Server Provisioning** - Create servers on DigitalOcean, Hetzner, Vultr
- **Stack Installation** - Nginx, PHP 8.3, MySQL/PostgreSQL, Redis, Node.js
- **SSH Key Management** - Add, sync, and revoke SSH keys across servers
- **Site Management** - Create Nginx sites with domain configuration
- **Git Integration** - Clone from GitHub/GitLab with deploy keys
- **Deployments** - Manual and automatic (webhook) deployments
- **Deployment Logs** - Realtime log streaming via WebSockets
- **Environment Variables** - Secure .env management per site
- **Deploy Scripts** - Customizable deployment commands

### Excluded from MVP ⛔ (Post-MVP)

- SSL Certificates (Let's Encrypt)
- Daemons/Queue Workers (Supervisor)
- Scheduled Jobs (Cron)
- Teams & Multi-user
- Billing & Subscriptions
- Server Monitoring
- Database Management
- Firewall Rules

## 🏗️ Tech Stack

| Layer | Technology |
|-------|------------|
| **Backend** | Laravel 12 |
| **Frontend** | React 18 + Inertia v2 |
| **UI Components** | Shadcn UI (Radix + Tailwind) |
| **Database** | PostgreSQL |
| **Cache/Queue** | Redis |
| **Realtime** | Laravel Reverb |
| **SSH Client** | phpseclib3 |

## 📊 High-Level Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   React UI      │────▶│   Laravel API   │────▶│   PostgreSQL    │
│   (Inertia)     │     │   Controllers   │     │   Database      │
└─────────────────┘     └────────┬────────┘     └─────────────────┘
                                 │
                                 ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Laravel Reverb │◀────│  Queue Workers  │────▶│  Cloud Provider │
│   (WebSocket)   │     │  (Horizon)      │     │  APIs           │
└─────────────────┘     └────────┬────────┘     └─────────────────┘
                                 │
                                 ▼
                        ┌─────────────────┐
                        │  Managed Servers│
                        │  (via SSH)      │
                        └─────────────────┘
```

## 🗃️ Database Tables

| Table | Purpose |
|-------|---------|
| `users` | User accounts |
| `provider_accounts` | Cloud provider API credentials |
| `servers` | Provisioned servers |
| `server_credentials` | SSH keys, passwords (encrypted) |
| `ssh_keys` | User SSH public keys |
| `server_ssh_key` | Key-to-server sync status |
| `sites` | Nginx sites on servers |
| `deployments` | Deployment history |
| `deployment_logs` | Realtime deployment output |
| `deploy_scripts` | Custom deploy scripts per site |
| `environment_variables` | .env variables (encrypted) |
| `server_actions` | Service restarts, reboots |
| `terminal_sessions` | Browser terminal sessions: who, which server, from where, when |

## 🔄 Core Workflows

### Server Provisioning

1. User selects provider, region, size
2. Backend creates server record + SSH keypair
3. `ProvisionServerJob` calls provider API
4. Polls until server is active
5. `InstallStackJob` runs provisioning script via SSH
6. Server marked as "active"

### Deployment

1. Trigger via UI, webhook, or API
2. Create deployment record
3. `DeploySiteJob` connects via SSH
4. Executes deploy script
5. Streams output to WebSocket
6. Updates deployment status

## 🔐 Security Highlights

- All secrets encrypted at rest (Laravel encrypted casts)
- SSH private keys never exposed in API responses
- Provider API tokens validated before storage
- Webhook signatures verified (GitHub/GitLab)
- Rate limiting on deployments
- CSRF protection via Inertia
- Authorization policies on all resources

## 🧪 Testing Strategy

```bash
# Unit tests - Services, Actions
php artisan test --testsuite=Unit

# Feature tests - Controllers, Workflows
php artisan test --testsuite=Feature

# Coverage report
php artisan test --coverage --min=80
```

Key testing approaches:

- HTTP mocking for provider APIs
- SSH mocking for server commands
- Event/Job assertions for async operations

## 🚀 Getting Started

```bash
# Clone and install
git clone <repo>
cd serverforge
composer install
npm install

# Environment
cp .env.example .env
php artisan key:generate

# Database
php artisan migrate

# Development
composer dev  # Runs PHP + Vite + Reverb + Horizon + Scheduler + Terminal gateway + Logs
```

## 📁 Project Structure

```
app/
├── Actions/           # Single-purpose action classes
├── Contracts/         # Interfaces (ProviderContract)
├── Data/              # DTOs (Spatie Laravel Data)
├── Enums/             # Status enums
├── Events/            # Broadcast events
├── Http/Controllers/  # Thin controllers
├── Jobs/              # Queue jobs
├── Models/            # Eloquent models
├── Policies/          # Authorization policies
└── Services/          # Business logic
    ├── Providers/     # Cloud provider integrations
    └── Ssh/           # SSH connection handling

resources/js/
├── components/        # React components
├── hooks/             # Custom React hooks
├── lib/               # Utilities
└── pages/             # Inertia pages
```

## 📅 Roadmap

| Phase | Timeline | Features |
|-------|----------|----------|
| **MVP** | Month 1-2 | Core provisioning, sites, deployments |
| **Phase 2** | Month 3-4 | SSL, Queue workers, Cron, DB management |
| **Phase 3** | Month 5-6 | Teams, Monitoring, Backups |
| **Phase 4** | Month 9-12 | Billing, Load balancers, Public API |

---

## 🔐 Free-domain SSL

See **[docs/FREE_SSL.md](docs/FREE_SSL.md)** for architecture, provisioning flow, renewal lifecycle, and design notes.

Sites on the free domain (`*.<FREE_DOMAIN>`) are served over HTTPS using a single Let's Encrypt **wildcard certificate** issued via the Cloudflare DNS-01 challenge and distributed to each server in the Forge-style ID-based layout `/etc/nginx/ssl/domains/{site_id}/{domain_id}/server.{crt,key}`.

**One-time setup on the FlitOps host (the machine running the queue worker):**

```bash
# Install acme.sh
curl https://get.acme.sh | sh -s email=<you@example.com>
```

Set the env vars (same `CLOUDFLARE_API_TOKEN` the DNS service already uses — needs `DNS:Edit` on the free-domain zone):

```env
CLOUDFLARE_API_TOKEN=...
CLOUDFLARE_ZONE_ID=...
FREE_DOMAIN=flitops.xyz
# Optional override; defaults to "acme.sh" on PATH
ACME_BINARY=/root/.acme.sh/acme.sh
```

**Issuance happens automatically** on a daily schedule (`Schedule::job(...)` in `routes/console.php`) and is idempotent — it only fires acme.sh when the existing cert is missing or within 30 days of expiry. The issuance job then dispatches a per-server distribution job for every server hosting at least one free-domain site.

**Bootstrap the first cert** manually:

```bash
php artisan flitops:wildcard:issue
```

---

## 💻 Browser terminal

Owners and Admins can open a live shell on a server from its **Terminal** tab. It logs in as the server user (`artisan`), which has passwordless `sudo`, so it reaches every site on the server, isolated ones included.

**How it works.** Three pieces, each with one job:

1. **The page** (`resources/js/components/terminal/server-terminal.tsx`) draws the terminal with xterm.js.
2. **Laravel** decides who may connect and records each session. It never carries terminal traffic.
3. **The gateway** (`terminal-gateway/`, a small Go program) holds the SSH connection and passes keystrokes and output between it and the browser over a WebSocket.

A session goes like this:

1. The page requires the `access_servers_as_admin` permission and a password confirmed in the last 15 minutes.
2. **Connect** asks Laravel for a token: random, single-use, valid for 60 seconds. Only its SHA-256 hash is stored, in `terminal_sessions`.
3. The browser opens a WebSocket to the gateway and sends the token as its first message (never in the URL, so it stays out of access logs). The gateway only accepts pages served from `ALLOWED_ORIGINS`.
4. The gateway redeems the token at `POST /internal/terminal-sessions/redeem`, authenticated with the shared `TERMINAL_GATEWAY_SECRET`. Laravel marks the session started and returns the server address and private key. The key never reaches the browser.
5. The gateway connects over SSH only if the server's host key matches the fingerprint Flitops recorded, then starts a shell on a PTY.
6. The session closes when the shell exits, the tab closes, after 15 minutes without typing, or after 2 hours. The gateway then calls `POST /internal/terminal-sessions/{id}/end`, and Laravel records the end time.

Each session records who opened it, on which server, from which IP, and when it started and ended. Keystrokes are not recorded, since that would capture passwords typed at prompts.

### Settings

| Where | Setting | Purpose |
|-------|---------|---------|
| Laravel `.env` | `TERMINAL_GATEWAY_URL` | The WebSocket address browsers connect to. Must be `wss://` when the app is served over HTTPS. |
| Laravel `.env` | `TERMINAL_GATEWAY_SECRET` | Shared with the gateway. Leave either setting empty to turn the terminal off. |
| Gateway env | `LISTEN_ADDR` | Where the gateway listens. Default `127.0.0.1:8090`. |
| Gateway env | `FLITOPS_URL` | Base URL of the Flitops app, for the internal endpoints. |
| Gateway env | `GATEWAY_SECRET` or `GATEWAY_SECRET_FILE` | The same value as `TERMINAL_GATEWAY_SECRET`. |
| Gateway env | `ALLOWED_ORIGINS` | Comma-separated hosts the Flitops pages are served from. |
| Gateway env | `IDLE_TIMEOUT`, `MAX_SESSION` | Defaults `15m` and `2h`. |

### Local setup (Herd)

Needs Go installed.

```bash
# A secure address for the gateway, since the app runs on https://
herd proxy flitops-terminal http://127.0.0.1:8090 --secure
```

```env
TERMINAL_GATEWAY_URL=wss://flitops-terminal.test/terminal
TERMINAL_GATEWAY_SECRET=   # generate with: openssl rand -hex 32
```

`composer dev` then builds and starts the gateway (`php artisan flitops:terminal-gateway`) with its settings taken from the app config. When the terminal isn't configured or Go isn't installed, it skips itself. Stop any gateway you started by hand first, or the port will already be taken.

### Production

Build a Linux binary (use `GOARCH=arm64` for ARM servers) and copy it to the Flitops host:

```bash
cd terminal-gateway
GOOS=linux GOARCH=amd64 go build -o flitops-terminal .
```

Run it as a supervisord worker next to Horizon and Reverb:

```ini
[program:flitops-terminal]
command=/usr/local/bin/flitops-terminal
user=artisan
autostart=true
autorestart=true
environment=LISTEN_ADDR="127.0.0.1:8090",FLITOPS_URL="https://flitops.example.com",ALLOWED_ORIGINS="flitops.example.com",GATEWAY_SECRET_FILE="/home/artisan/.flitops/terminal-gateway-secret"
stdout_logfile=/home/artisan/.flitops/logs/terminal.log
redirect_stderr=true
```

Proxy `/terminal` to it in nginx, with the WebSocket upgrade headers:

```nginx
location /terminal {
    proxy_pass http://127.0.0.1:8090;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3h;
}
```

Then set `TERMINAL_GATEWAY_URL=wss://flitops.example.com/terminal` and `TERMINAL_GATEWAY_SECRET` in the app's `.env`.

**Tests:** `php artisan test --compact tests/Feature/ServerTerminalTest.php tests/Feature/TerminalGatewayTest.php` for the Laravel side, and `cd terminal-gateway && go test -race ./...` for the gateway, which runs a real SSH server in-process.

---

## License

[MIT](LICENSE)
