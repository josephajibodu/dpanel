# ServerForge - Laravel Forge MVP Clone

A server management and application deployment platform inspired by [Laravel Forge](https://forge.laravel.com/), built with Laravel 12, Inertia v2, React, and Shadcn UI.

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
composer dev  # Runs PHP + Vite + Reverb + Queue
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

## License

[MIT](LICENSE)
