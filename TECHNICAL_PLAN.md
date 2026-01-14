# Laravel Forge MVP Clone - Technical Plan

**Project Name:** ServerForge (working title)  
**Tech Stack:** Laravel 12, Inertia v2, React, Shadcn UI  
**Document Version:** 1.0  
**Date:** January 2026

---

## Table of Contents

1. [Product Overview](#1-product-overview)
2. [MVP Feature Scope](#2-mvp-feature-scope)
3. [System Architecture](#3-system-architecture)
4. [Database Schema](#4-database-schema)
5. [Core Workflows](#5-core-workflows)
6. [Jobs & Queues](#6-jobs--queues)
7. [SSH Architecture](#7-ssh-architecture)
8. [Controllers, Services & Actions](#8-controllers-services--actions)
9. [API Endpoints](#9-api-endpoints)
10. [UI Screens](#10-ui-screens)
11. [Realtime Features](#11-realtime-features)
12. [Security Considerations](#12-security-considerations)
13. [Testing Strategy](#13-testing-strategy)
14. [Future Roadmap](#14-future-roadmap)

---

## 1. Product Overview

### What This MVP Is

ServerForge is a **server management and application deployment platform** that automates the provisioning, configuration, and management of cloud servers for PHP/Laravel applications. It provides a unified web interface to:

- Provision servers on major cloud providers (DigitalOcean, Hetzner, Vultr)
- Configure web servers (Nginx), PHP, databases (MySQL/PostgreSQL), and Redis
- Deploy applications from Git repositories
- Manage SSH keys and server access
- Handle environment variables securely

### What Problems It Solves

| Problem | Solution |
|---------|----------|
| **Complex server setup** | One-click provisioning with pre-configured stack (Nginx, PHP 8.x, MySQL, Redis, Composer, Node.js) |
| **Manual SSH configuration** | Automated SSH key management and distribution across servers |
| **Error-prone deployments** | Standardized deployment scripts with rollback capability |
| **Environment management** | Secure encrypted storage and synchronization of environment variables |
| **DevOps knowledge barrier** | Abstracted server management for developers without sysadmin experience |

### Target Users

1. **Solo Developers** - Need quick, reliable deployments without DevOps overhead
2. **Small Development Teams** - Share server access and deployment capabilities
3. **Freelancers/Agencies** - Manage multiple client projects across servers
4. **Startups** - Scale infrastructure without hiring dedicated DevOps

### MVP vs Full Product Scope

The MVP focuses on the **core value proposition**: provisioning servers and deploying applications. Advanced features are deferred to reduce time-to-market while validating product-market fit.

---

## 2. MVP Feature Scope

| Feature | Description | MVP | Post-MVP | Priority |
|---------|-------------|:---:|:--------:|:--------:|
| **User Authentication** | Registration, login, password reset | ✅ | | P0 |
| **Provider Integration** | Connect cloud provider accounts (DO, Hetzner, Vultr) | ✅ | | P0 |
| **Server Provisioning** | Create servers via provider API | ✅ | | P0 |
| **Server Types** | App Server only | ✅ | Database, Worker, Load Balancer | P0 |
| **Stack Installation** | Nginx, PHP 8.3, MySQL 8, Redis, Composer, Node.js | ✅ | | P0 |
| **SSH Key Management** | Add/remove/sync keys to servers | ✅ | | P0 |
| **Site Creation** | Create Nginx sites with domain configuration | ✅ | | P0 |
| **Git Integration** | Clone repos from GitHub/GitLab | ✅ | Bitbucket | P0 |
| **Manual Deployment** | Trigger deployments via UI | ✅ | | P0 |
| **Auto-Deploy (Webhook)** | Push-to-deploy via webhook | ✅ | | P1 |
| **Deployment Scripts** | Customizable deployment commands | ✅ | | P1 |
| **Deployment Logs** | View deployment output in realtime | ✅ | | P1 |
| **Environment Variables** | Manage .env files per site | ✅ | | P1 |
| **Server Actions** | Restart services (Nginx, PHP-FPM, MySQL) | ✅ | | P1 |
| **SSL Certificates** | Let's Encrypt auto-provisioning | ⛔ | ✅ | P2 |
| **Daemons/Queues** | Supervisor-managed queue workers | ⛔ | ✅ | P2 |
| **Cron/Scheduler** | Manage cron jobs | ⛔ | ✅ | P2 |
| **Database Management** | Create databases and users | ⛔ | ✅ | P2 |
| **Firewall Rules** | UFW management | ⛔ | ✅ | P2 |
| **Teams** | Multi-user with roles | ⛔ | ✅ | P3 |
| **Billing** | Stripe subscriptions | ⛔ | ✅ | P3 |
| **Server Monitoring** | CPU/Memory/Disk metrics | ⛔ | ✅ | P3 |
| **Backups** | Automated database backups | ⛔ | ✅ | P3 |

### MVP Feature Details

#### Server Provisioning

- Support for DigitalOcean, Hetzner, and Vultr initially
- Ubuntu 22.04 LTS only
- Predefined server sizes (mapped to provider offerings)
- Regions exposed per provider

#### Stack Components (Ubuntu 22.04)

```
- Nginx 1.24+
- PHP 8.3 (with common extensions: mbstring, xml, curl, mysql, redis, gd, zip, bcmath)
- MySQL 8.0 OR PostgreSQL 15 (user choice at provisioning)
- Redis 7.x
- Composer 2.x
- Node.js 20 LTS + npm
- Git
- Supervisor
- UFW (basic rules)
```

#### Deployment Script (Default)

```bash
cd /home/forge/{site_name}
git pull origin {branch}
composer install --no-interaction --prefer-dist --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

---

## 3. System Architecture

### High-Level Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                   USERS                                      │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │ HTTPS
                                  ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         LOAD BALANCER / REVERSE PROXY                        │
│                              (Nginx / Caddy)                                 │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           APPLICATION SERVER                                 │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                        Laravel 12 Application                        │    │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────────┐  │    │
│  │  │   Inertia    │  │   Laravel    │  │      API Controllers      │  │    │
│  │  │   + React    │  │   Backend    │  │    (Internal + Webhook)   │  │    │
│  │  │   Frontend   │  │              │  │                          │  │    │
│  │  └──────────────┘  └──────────────┘  └──────────────────────────┘  │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
└─────────────────────────┬───────────────────────┬───────────────────────────┘
                          │                       │
              ┌───────────┴───────────┐           │
              ▼                       ▼           ▼
┌──────────────────────┐  ┌──────────────────────────────────────────────────┐
│      PostgreSQL      │  │                    Redis                          │
│    (Primary DB)      │  │  ┌─────────────┐  ┌─────────────┐  ┌──────────┐  │
│                      │  │  │   Cache     │  │   Queue     │  │ Sessions │  │
│  - Users             │  │  │             │  │             │  │          │  │
│  - Servers           │  │  └─────────────┘  └─────────────┘  └──────────┘  │
│  - Sites             │  │                                                   │
│  - Deployments       │  │                                                   │
│  - etc.              │  │                                                   │
└──────────────────────┘  └──────────────────────────────────────────────────┘
                                            │
                                            │ Jobs
                                            ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            QUEUE WORKERS                                     │
│  ┌───────────────────────────────────────────────────────────────────────┐  │
│  │  Laravel Horizon (or standard queue workers)                          │  │
│  │                                                                       │  │
│  │  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐       │  │
│  │  │ provision queue │  │  deploy queue   │  │   ssh queue     │       │  │
│  │  │   (weight: 3)   │  │   (weight: 2)   │  │   (weight: 1)   │       │  │
│  │  └─────────────────┘  └─────────────────┘  └─────────────────┘       │  │
│  └───────────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
          ┌───────────────────────┼───────────────────────┐
          │                       │                       │
          ▼                       ▼                       ▼
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────────────────┐
│  CLOUD PROVIDER  │  │  CLOUD PROVIDER  │  │        MANAGED SERVERS        │
│   APIS           │  │   APIS           │  │                              │
│  ┌────────────┐  │  │  ┌────────────┐  │  │  ┌────────────────────────┐  │
│  │DigitalOcean│  │  │  │  Hetzner   │  │  │  │   SSH Connection       │  │
│  └────────────┘  │  │  └────────────┘  │  │  │   (Port 22)            │  │
│  ┌────────────┐  │  │  ┌────────────┐  │  │  │                        │  │
│  │   Vultr    │  │  │  │  (more)    │  │  │  │   Execute provisioning │  │
│  └────────────┘  │  │  └────────────┘  │  │  │   & deployment scripts │  │
└──────────────────┘  └──────────────────┘  │  └────────────────────────┘  │
                                           └──────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                          REALTIME (Laravel Reverb)                           │
│                                                                              │
│  Broadcasting channels for:                                                  │
│  - Deployment log streaming                                                  │
│  - Server status updates                                                     │
│  - Provisioning progress                                                     │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Technology | Responsibility |
|-----------|------------|----------------|
| **Frontend** | React + Inertia + Shadcn | UI rendering, form handling, realtime updates |
| **Backend** | Laravel 12 | Business logic, API, authentication, authorization |
| **Database** | PostgreSQL | Persistent storage, relational data |
| **Cache** | Redis | Query caching, rate limiting |
| **Queue** | Redis + Laravel Horizon | Background job processing |
| **Realtime** | Laravel Reverb | WebSocket connections for live updates |
| **SSH Client** | phpseclib3 | Remote command execution on managed servers |
| **Provider SDK** | HTTP Client | API calls to DigitalOcean, Hetzner, Vultr |

### Request Lifecycle

#### Synchronous Request (e.g., List Servers)

```
User Click → Inertia Request → ServerController@index → 
  → Server::query() → Database → 
  → Return Inertia Response → React Renders
```

#### Asynchronous Request (e.g., Provision Server)

```
User Click → Inertia Request → ServerController@store →
  → Validate Input → Create Server (status: pending) →
  → Dispatch ProvisionServerJob → Return Inertia Response →
  → React Shows "Provisioning..." with progress updates

[Background - Queue Worker]
ProvisionServerJob →
  → CreateDropletAction (Provider API) →
  → Wait for server to be active →
  → Dispatch InstallStackJob

InstallStackJob →
  → SSH Connect → Run provisioning script →
  → Broadcast progress → Update server status →
  → Server ready
```

### Queue Architecture

```yaml
queues:
  provision:
    - ProvisionServerJob
    - InstallStackJob
    - ConfigureFirewallJob
    
  deploy:
    - DeploySiteJob
    - RunDeploymentScriptJob
    
  ssh:
    - SyncSshKeysJob
    - RestartServiceJob
    - ExecuteCommandJob
    
  default:
    - SendNotificationJob
    - CleanupJob
```

**Queue Priority:** `provision` > `deploy` > `ssh` > `default`

---

## 4. Database Schema

### Entity Relationship Overview

```
┌─────────┐       ┌──────────────────┐       ┌─────────┐
│  users  │───1:N─│ provider_accounts│       │ssh_keys │
└────┬────┘       └──────────────────┘       └────┬────┘
     │                                            │
     │ 1:N                                        │ M:N
     ▼                                            ▼
┌─────────┐       ┌──────────────────┐    ┌─────────────────┐
│ servers │───1:N─│     sites        │    │ server_ssh_key  │
└────┬────┘       └────────┬─────────┘    └─────────────────┘
     │                     │
     │ 1:N                 │ 1:N
     ▼                     ▼
┌──────────────┐   ┌──────────────────┐
│server_actions│   │   deployments    │
└──────────────┘   └────────┬─────────┘
                           │
                           │ 1:N
                           ▼
                   ┌──────────────────┐
                   │ deployment_logs  │
                   └──────────────────┘
                   
┌─────────┐       
│  sites  │───1:N─┬───────────────────────┐
└─────────┘       │                       │
                  ▼                       ▼
          ┌──────────────────┐   ┌──────────────────┐
          │environment_vars  │   │  deploy_scripts  │
          └──────────────────┘   └──────────────────┘
```

### Detailed Table Definitions

#### `users`

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| ulid | CHAR(26) | NO | | UNIQUE | Public identifier |
| name | VARCHAR(255) | NO | | | User's full name |
| email | VARCHAR(255) | NO | | UNIQUE | Login email |
| email_verified_at | TIMESTAMP | YES | NULL | | |
| password | VARCHAR(255) | NO | | | Bcrypt hash |
| remember_token | VARCHAR(100) | YES | NULL | | |
| created_at | TIMESTAMP | YES | NULL | | |
| updated_at | TIMESTAMP | YES | NULL | | |

#### `provider_accounts`

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| ulid | CHAR(26) | NO | | UNIQUE | Public identifier |
| user_id | BIGINT UNSIGNED | NO | | INDEX | Owner |
| provider | VARCHAR(50) | NO | | INDEX | digitalocean, hetzner, vultr |
| name | VARCHAR(255) | NO | | | Display name (e.g., "My DO Account") |
| credentials | TEXT | NO | | | Encrypted JSON (API token, etc.) |
| is_valid | BOOLEAN | NO | true | | Last validation status |
| validated_at | TIMESTAMP | YES | NULL | | Last successful validation |
| created_at | TIMESTAMP | YES | NULL | | |
| updated_at | TIMESTAMP | YES | NULL | | |

**Indexes:**

- `provider_accounts_user_id_foreign` (user_id)
- `provider_accounts_user_provider_index` (user_id, provider)

#### `servers`

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| ulid | CHAR(26) | NO | | UNIQUE | Public identifier |
| user_id | BIGINT UNSIGNED | NO | | INDEX | Owner |
| provider_account_id | BIGINT UNSIGNED | NO | | INDEX | Provider account used |
| provider | VARCHAR(50) | NO | | INDEX | digitalocean, hetzner, vultr |
| provider_server_id | VARCHAR(255) | YES | NULL | INDEX | Provider's server ID |
| name | VARCHAR(255) | NO | | | Server display name |
| size | VARCHAR(50) | NO | | | Size slug (e.g., s-1vcpu-1gb) |
| region | VARCHAR(50) | NO | | | Region slug (e.g., nyc1) |
| ip_address | VARCHAR(45) | YES | NULL | INDEX | Public IPv4 |
| private_ip_address | VARCHAR(45) | YES | NULL | | Private network IP |
| status | VARCHAR(50) | NO | 'pending' | INDEX | See status enum below |
| php_version | VARCHAR(10) | NO | '8.3' | | Installed PHP version |
| database_type | VARCHAR(20) | NO | 'mysql' | | mysql or postgresql |
| ssh_port | SMALLINT UNSIGNED | NO | 22 | | SSH port |
| sudo_password | TEXT | YES | NULL | | Encrypted sudo password |
| database_password | TEXT | YES | NULL | | Encrypted root DB password |
| provisioned_at | TIMESTAMP | YES | NULL | | When provisioning completed |
| last_ssh_connection_at | TIMESTAMP | YES | NULL | | Last successful SSH |
| meta | JSON | YES | NULL | | Additional provider metadata |
| created_at | TIMESTAMP | YES | NULL | | |
| updated_at | TIMESTAMP | YES | NULL | | |

**Server Status Enum:**

```php
enum ServerStatus: string {
    case PENDING = 'pending';           // Created in DB, not yet at provider
    case CREATING = 'creating';         // API call sent to provider
    case PROVISIONING = 'provisioning'; // Server exists, installing stack
    case ACTIVE = 'active';             // Ready to use
    case ERROR = 'error';               // Provisioning failed
    case DELETING = 'deleting';         // Deletion in progress
}
```

#### `ssh_keys`

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| ulid | CHAR(26) | NO | | UNIQUE | Public identifier |
| user_id | BIGINT UNSIGNED | NO | | INDEX | Owner |
| name | VARCHAR(255) | NO | | | Display name |
| public_key | TEXT | NO | | | SSH public key content |
| fingerprint | VARCHAR(255) | NO | | UNIQUE | SSH key fingerprint |
| created_at | TIMESTAMP | YES | NULL | | |
| updated_at | TIMESTAMP | YES | NULL | | |

#### `server_ssh_key` (Pivot)

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| server_id | BIGINT UNSIGNED | NO | | INDEX | |
| ssh_key_id | BIGINT UNSIGNED | NO | | INDEX | |
| status | VARCHAR(20) | NO | 'pending' | | pending, synced, failed |
| synced_at | TIMESTAMP | YES | NULL | | When key was synced |
| created_at | TIMESTAMP | YES | NULL | | |

**Indexes:**

- `server_ssh_key_unique` (server_id, ssh_key_id) UNIQUE

#### `sites`

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| ulid | CHAR(26) | NO | | UNIQUE | Public identifier |
| server_id | BIGINT UNSIGNED | NO | | INDEX | Host server |
| domain | VARCHAR(255) | NO | | INDEX | Primary domain |
| aliases | JSON | YES | NULL | | Additional domains |
| directory | VARCHAR(255) | NO | '/public' | | Web root relative to site |
| repository | VARCHAR(255) | YES | NULL | | Git repository URL |
| repository_provider | VARCHAR(50) | YES | NULL | | github, gitlab, custom |
| branch | VARCHAR(255) | NO | 'main' | | Git branch |
| project_type | VARCHAR(50) | NO | 'laravel' | | laravel, php, static |
| php_version | VARCHAR(10) | NO | '8.3' | | PHP version for this site |
| status | VARCHAR(50) | NO | 'pending' | INDEX | See status enum |
| deploy_key_id | VARCHAR(255) | YES | NULL | | Deploy key ID at provider |
| webhook_secret | VARCHAR(255) | YES | NULL | | Webhook validation secret |
| auto_deploy | BOOLEAN | NO | false | | Enable push-to-deploy |
| deployment_started_at | TIMESTAMP | YES | NULL | | |
| deployment_finished_at | TIMESTAMP | YES | NULL | | |
| created_at | TIMESTAMP | YES | NULL | | |
| updated_at | TIMESTAMP | YES | NULL | | |

**Site Status Enum:**

```php
enum SiteStatus: string {
    case PENDING = 'pending';
    case INSTALLING = 'installing';
    case DEPLOYED = 'deployed';
    case DEPLOYING = 'deploying';
    case FAILED = 'failed';
}
```

#### `environment_variables`

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| site_id | BIGINT UNSIGNED | NO | | INDEX | Parent site |
| key | VARCHAR(255) | NO | | | Variable name |
| value | TEXT | NO | | | Encrypted value |
| created_at | TIMESTAMP | YES | NULL | | |
| updated_at | TIMESTAMP | YES | NULL | | |

**Indexes:**

- `environment_variables_site_key_unique` (site_id, key) UNIQUE

#### `deployments`

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| ulid | CHAR(26) | NO | | UNIQUE | Public identifier |
| site_id | BIGINT UNSIGNED | NO | | INDEX | Parent site |
| user_id | BIGINT UNSIGNED | YES | NULL | INDEX | Who triggered (null = webhook) |
| commit_hash | VARCHAR(40) | YES | NULL | | Git commit SHA |
| commit_message | VARCHAR(255) | YES | NULL | | Truncated commit message |
| commit_author | VARCHAR(255) | YES | NULL | | |
| status | VARCHAR(50) | NO | 'pending' | INDEX | See status enum |
| started_at | TIMESTAMP | YES | NULL | | |
| finished_at | TIMESTAMP | YES | NULL | | |
| duration_seconds | INT UNSIGNED | YES | NULL | | |
| triggered_by | VARCHAR(50) | NO | 'manual' | | manual, webhook, api |
| created_at | TIMESTAMP | YES | NULL | | |
| updated_at | TIMESTAMP | YES | NULL | | |

**Deployment Status Enum:**

```php
enum DeploymentStatus: string {
    case PENDING = 'pending';
    case RUNNING = 'running';
    case FINISHED = 'finished';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
```

#### `deployment_logs`

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| deployment_id | BIGINT UNSIGNED | NO | | INDEX | Parent deployment |
| type | VARCHAR(20) | NO | 'output' | | output, error, info |
| message | TEXT | NO | | | Log line content |
| created_at | TIMESTAMP | YES | NULL | INDEX | For ordering |

#### `deploy_scripts`

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| site_id | BIGINT UNSIGNED | NO | | INDEX, UNIQUE | One script per site |
| script | TEXT | NO | | | Bash script content |
| created_at | TIMESTAMP | YES | NULL | | |
| updated_at | TIMESTAMP | YES | NULL | | |

#### `server_actions`

| Column | Type | Nullable | Default | Index | Description |
|--------|------|----------|---------|-------|-------------|
| id | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY | |
| ulid | CHAR(26) | NO | | UNIQUE | Public identifier |
| server_id | BIGINT UNSIGNED | NO | | INDEX | Target server |
| user_id | BIGINT UNSIGNED | YES | NULL | INDEX | Who triggered |
| action | VARCHAR(100) | NO | | INDEX | Action type (see below) |
| status | VARCHAR(50) | NO | 'pending' | INDEX | pending, running, completed, failed |
| output | TEXT | YES | NULL | | Command output |
| error | TEXT | YES | NULL | | Error message if failed |
| started_at | TIMESTAMP | YES | NULL | | |
| finished_at | TIMESTAMP | YES | NULL | | |
| created_at | TIMESTAMP | YES | NULL | | |
| updated_at | TIMESTAMP | YES | NULL | | |

**Action Types:**

- `restart_nginx`
- `restart_php`
- `restart_mysql`
- `restart_redis`
- `restart_supervisor`
- `reboot_server`
- `run_command` (custom)

#### `jobs` (Laravel Default)

Standard Laravel jobs table for queue management.

#### `failed_jobs` (Laravel Default)

Standard Laravel failed_jobs table.

### Eloquent Relationships

```php
// User.php
public function providerAccounts(): HasMany
public function servers(): HasMany
public function sshKeys(): HasMany
public function deployments(): HasMany

// ProviderAccount.php
public function user(): BelongsTo
public function servers(): HasMany

// Server.php
public function user(): BelongsTo
public function providerAccount(): BelongsTo
public function sites(): HasMany
public function sshKeys(): BelongsToMany
public function actions(): HasMany

// SshKey.php
public function user(): BelongsTo
public function servers(): BelongsToMany

// Site.php
public function server(): BelongsTo
public function deployments(): HasMany
public function environmentVariables(): HasMany
public function deployScript(): HasOne
public function latestDeployment(): HasOne

// Deployment.php
public function site(): BelongsTo
public function user(): BelongsTo
public function logs(): HasMany

// DeploymentLog.php
public function deployment(): BelongsTo

// EnvironmentVariable.php
public function site(): BelongsTo

// DeployScript.php
public function site(): BelongsTo

// ServerAction.php
public function server(): BelongsTo
public function user(): BelongsTo
```

---

*Continued in TECHNICAL_PLAN_PART2.md*
