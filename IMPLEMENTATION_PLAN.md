# ServerForge - Implementation Plan

This document provides a **sequential, trackable guide** for building the Laravel Forge MVP clone. Check off tasks as you complete them.

**Reference Documents:**

- `TECHNICAL_PLAN.md` - Architecture, database schema
- `TECHNICAL_PLAN_PART2.md` - Workflows, jobs & queues
- `TECHNICAL_PLAN_PART3.md` - SSH, controllers, API endpoints
- `TECHNICAL_PLAN_PART4.md` - UI screens, security, testing

---

## Progress Overview

| Phase | Status | Tasks | Completed |
|-------|--------|-------|-----------|
| 0. Scaffolding | 🟢 Complete | 8 | 8/8 |
| 1. Foundation | 🟢 Complete | 12 | 12/12 |
| 2. Provider Integration | 🔴 Not Started | 10 | 0/10 |
| 3. Server Provisioning | 🔴 Not Started | 14 | 0/14 |
| 4. SSH & Key Management | 🔴 Not Started | 9 | 0/9 |
| 5. Sites | 🔴 Not Started | 11 | 0/11 |
| 6. Deployments | 🔴 Not Started | 12 | 0/12 |
| 7. Polish & Testing | 🔴 Not Started | 10 | 0/10 |

**Legend:** 🔴 Not Started | 🟡 In Progress | 🟢 Complete

---

## Phase 0: Project Scaffolding

**Goal:** Get the development environment running with all dependencies installed.

**Estimated Time:** 2-3 hours

### Tasks

- [x] **0.1** Create Laravel 12 project (using Laravel React Starter Kit)

  ```bash
  composer create-project laravel/laravel . --prefer-dist
  ```

- [x] **0.2** Install and configure Inertia.js (comes with starter kit)

- [x] **0.3** Install React + TypeScript (comes with starter kit)

- [x] **0.4** Configure Vite for React (comes with starter kit)

- [x] **0.5** Install and initialize Shadcn UI (comes with starter kit)

- [x] **0.6** Install core Shadcn components (comes with starter kit)

- [x] **0.7** Install backend dependencies (to be installed as needed)

- [x] **0.8** Configure environment (comes with starter kit)

### Milestone 0 ✓

- [x] App boots with React + Inertia working
- [x] Shadcn components render correctly

---

## Phase 1: Foundation

**Goal:** Set up database, models, authentication, and base UI layout.

**Estimated Time:** 1-2 days

**Dependencies:** Phase 0 complete

### 1.1 Database Migrations

- [x] **1.1.1** Create `provider_accounts` migration
  - Ref: `TECHNICAL_PLAN.md` → Section 4 → `provider_accounts`

- [x] **1.1.2** Create `servers` migration
  - Ref: `TECHNICAL_PLAN.md` → Section 4 → `servers`

- [x] **1.1.3** Create `server_credentials` migration
  - Ref: `TECHNICAL_PLAN_PART3.md` → Section 7 → Key Storage

- [x] **1.1.4** Create `ssh_keys` migration
  - Ref: `TECHNICAL_PLAN.md` → Section 4 → `ssh_keys`

- [x] **1.1.5** Create `server_ssh_key` pivot migration

- [x] **1.1.6** Create `sites` migration
  - Ref: `TECHNICAL_PLAN.md` → Section 4 → `sites`

- [x] **1.1.7** Create `deployments` migration
  - Ref: `TECHNICAL_PLAN.md` → Section 4 → `deployments`

- [x] **1.1.8** Create `deployment_logs` migration

- [x] **1.1.9** Create `deploy_scripts` migration

- [x] **1.1.10** Create `environment_variables` migration

- [x] **1.1.11** Create `server_actions` migration

- [x] **1.1.12** Run all migrations

  ```bash
  php artisan migrate
  ```

### 1.2 Enums

- [x] **1.2.1** Create `App\Enums\ServerStatus`

  ```php
  enum ServerStatus: string {
      case Pending = 'pending';
      case Creating = 'creating';
      case Provisioning = 'provisioning';
      case Active = 'active';
      case Error = 'error';
      case Deleting = 'deleting';
  }
  ```

- [x] **1.2.2** Create `App\Enums\SiteStatus`

- [x] **1.2.3** Create `App\Enums\DeploymentStatus`

- [x] **1.2.4** Create `App\Enums\Provider`

### 1.3 Eloquent Models

- [x] **1.3.1** Create `ProviderAccount` model with relationships

- [x] **1.3.2** Create `Server` model with relationships + encrypted casts

- [x] **1.3.3** Create `ServerCredential` model with encrypted cast

- [x] **1.3.4** Create `SshKey` model

- [x] **1.3.5** Create `Site` model

- [x] **1.3.6** Create `Deployment` model

- [x] **1.3.7** Create `DeploymentLog` model

- [x] **1.3.8** Create `DeployScript` model

- [x] **1.3.9** Create `EnvironmentVariable` model

- [x] **1.3.10** Create `ServerAction` model

### 1.4 Authentication

- [x] **1.4.1** Authentication already comes with the starter kit installed from Laravel React Starterkit

### 1.5 Base Layout (Already done as it comes with the starter kit)

- [x] **1.5.1** Create `AppLayout` component with sidebar
  - Ref: `TECHNICAL_PLAN_PART4.md` → Section 10 → Component Structure

- [x] **1.5.2** Create `Header` component

- [x] **1.5.3** Create `Sidebar` component with navigation

- [x] **1.5.4** Create shared components:
  - `StatusBadge`
  - `LoadingSpinner` (using existing Spinner component)
  - `EmptyState`
  - `ConfirmDialog`
  - `CopyButton`

### Milestone 1 ✓

- [x] User can register and login
- [x] Dashboard page renders with layout
- [x] All migrations run successfully
- [x] Models have correct relationships

---

## Phase 2: Provider Integration 🟢 Complete

**Goal:** Connect cloud provider accounts and validate credentials.

**Estimated Time:** 2-3 days

**Dependencies:** Phase 1 complete

### 2.1 Provider Infrastructure

- [x] **2.1.1** Create `App\Contracts\ProviderContract` interface
  - Ref: `TECHNICAL_PLAN_PART3.md` → Section 8 → ProviderContract

- [x] **2.1.2** Create `App\Services\Providers\ProviderManager`

- [x] **2.1.3** Create provider DTOs:
  - `ProviderServerResult`
  - `ProviderServerStatus`
  - `ProviderRegion`
  - `ProviderSize`

### 2.2 DigitalOcean Provider (Primary)

- [x] **2.2.1** Create `App\Services\Providers\DigitalOceanProvider`
  - Ref: `TECHNICAL_PLAN_PART3.md` → Section 8 → DigitalOcean Provider

- [x] **2.2.2** Implement `validateCredentials()`

- [x] **2.2.3** Implement `getRegions()`

- [x] **2.2.4** Implement `getSizes()`

- [x] **2.2.5** Implement `createServer()`

- [x] **2.2.6** Implement `getServerStatus()`

- [x] **2.2.7** Implement `deleteServer()`

- [x] **2.2.8** Implement `createSshKey()` / `deleteSshKey()`

### 2.3 Provider Account Management

- [x] **2.3.1** Create `ProviderAccountController`
  - index, create, store, show, destroy

- [x] **2.3.2** Create `StoreProviderAccountRequest` with validation

- [x] **2.3.3** Create `ProviderAccountResource`

- [x] **2.3.4** Create `ValidateProviderJob`

### 2.4 Provider Account UI

- [x] **2.4.1** Create `pages/provider-accounts/index.tsx`
  - List all connected accounts

- [x] **2.4.2** Create `pages/provider-accounts/create.tsx`
  - Form to add new provider

- [x] **2.4.3** Create `components/provider-accounts/provider-card.tsx`

- [x] **2.4.4** Add provider accounts link to sidebar

### 2.5 Additional Providers

- [x] **2.5.1** Create `HetznerProvider` (similar to DO)

- [x] **2.5.2** Create `VultrProvider` (similar to DO)

### Milestone 2 ✓

- [x] User can connect DigitalOcean account
- [x] Credentials are validated via API
- [x] Regions and sizes can be fetched
- [x] Provider accounts show in list

---

## Phase 3: Server Provisioning

**Goal:** Provision real servers on DigitalOcean with full stack installation.

**Estimated Time:** 4-5 days

**Dependencies:** Phase 2 complete

### 3.1 SSH Infrastructure 🟢 Complete

- [x] **3.1.1** Create `App\Services\Ssh\KeyGenerator`
  - Ref: `TECHNICAL_PLAN_PART3.md` → Section 7 → Key Generation

- [x] **3.1.2** Create `App\Data\KeyPair` DTO

- [x] **3.1.3** Test key generation works locally

### 3.2 Server Creation Flow 🟢 Complete

- [x] **3.2.1** Create `App\Data\ServerData` DTO

- [x] **3.2.2** Create `App\Actions\Servers\CreateServerAction`
  - Ref: `TECHNICAL_PLAN_PART3.md` → Section 8 → CreateServerAction

- [x] **3.2.3** Create `StoreServerRequest` with validation

- [x] **3.2.4** Create `ServerController` (index, create, store, show, destroy)

- [x] **3.2.5** Create `ServerResource` (already created in Phase 2)

- [x] **3.2.6** Create `ServerPolicy` for authorization

### 3.3 Provisioning Jobs 🟢 Complete

- [x] **3.3.1** Create `App\Jobs\ProvisionServerJob`
  - Ref: `TECHNICAL_PLAN_PART2.md` → Section 6 → ProvisionServerJob

- [x] **3.3.2** Create `App\Jobs\InstallStackJob`
  - Ref: `TECHNICAL_PLAN_PART2.md` → Section 5A → Provisioning Script

- [x] **3.3.3** Create `App\Services\ProvisioningScriptService`
  - Generate bash script with variables

- [x] **3.3.4** Create `App\Jobs\DeleteServerJob`

### 3.4 SSH Service 🟢 Complete

- [x] **3.4.1** Create `App\Services\Ssh\SshService`
  - Ref: `TECHNICAL_PLAN_PART3.md` → Section 7 → SSH Connection Service

- [x] **3.4.2** Create `App\Services\Ssh\SshConnection`

- [x] **3.4.3** Create `App\Services\Ssh\SshRetryHandler`

- [x] **3.4.4** Create SSH exceptions:
  - `SshConnectionException`
  - `SshCommandException`

- [ ] **3.4.5** Test SSH connection to a real server (requires live server)

### 3.5 Realtime Updates

- [ ] **3.5.1** Configure Laravel Reverb

  ```bash
  php artisan reverb:install
  ```

- [ ] **3.5.2** Create `App\Events\ServerStatusChanged`

- [ ] **3.5.3** Set up broadcasting channel for servers

- [ ] **3.5.4** Create `hooks/use-server-status.ts` on frontend

### 3.6 Server UI

- [ ] **3.6.1** Create `pages/servers/index.tsx`
  - List all servers with status

- [ ] **3.6.2** Create `pages/servers/create.tsx`
  - Form with provider, region, size selection

- [ ] **3.6.3** Create `pages/servers/show.tsx`
  - Server details, sites list, actions

- [ ] **3.6.4** Create `components/servers/server-card.tsx`

- [ ] **3.6.5** Create `components/servers/server-status-badge.tsx`

- [ ] **3.6.6** Create `components/servers/provision-progress.tsx`
  - Shows progress during provisioning

### 3.7 Queue Configuration

- [ ] **3.7.1** Configure Horizon for queue management

  ```bash
  php artisan horizon:install
  ```

- [ ] **3.7.2** Set up queue priorities (provision > deploy > ssh)

### Milestone 3 ✓

- [ ] User can create a server on DigitalOcean
- [ ] Server provisions with full stack (Nginx, PHP, MySQL, Redis)
- [ ] Status updates show in realtime
- [ ] User can view server details
- [ ] User can delete a server

---

## Phase 4: SSH Key Management

**Goal:** Manage SSH keys and sync them to servers.

**Estimated Time:** 2 days

**Dependencies:** Phase 3 complete

### 4.1 SSH Key Backend

- [ ] **4.1.1** Create `SshKeyController` (index, store, destroy)

- [ ] **4.1.2** Create `StoreSshKeyRequest`
  - Validate key format (ssh-rsa, ssh-ed25519)
  - Calculate fingerprint

- [ ] **4.1.3** Create `SshKeyResource`

- [ ] **4.1.4** Create `SshKeyPolicy`

### 4.2 Key Sync Jobs

- [ ] **4.2.1** Create `App\Jobs\SyncSshKeyJob`
  - Ref: `TECHNICAL_PLAN_PART2.md` → Section 5B → SSH Key Management

- [ ] **4.2.2** Create `App\Jobs\RevokeSshKeyJob`

- [ ] **4.2.3** Create `App\Actions\SshKeys\SyncSshKeyAction`

### 4.3 SSH Key UI

- [ ] **4.3.1** Create `pages/ssh-keys/index.tsx`

- [ ] **4.3.2** Create `components/ssh-keys/ssh-key-card.tsx`

- [ ] **4.3.3** Create `components/ssh-keys/add-key-dialog.tsx`

- [ ] **4.3.4** Create `components/ssh-keys/sync-servers-dialog.tsx`

### 4.4 Server Actions

- [ ] **4.4.1** Create `App\Jobs\RestartServiceJob`

- [ ] **4.4.2** Add restart endpoints to `ServerController`

- [ ] **4.4.3** Create `components/servers/restart-dropdown.tsx`

### Milestone 4 ✓

- [ ] User can add SSH keys
- [ ] Keys can be synced to selected servers
- [ ] Keys can be revoked from servers
- [ ] User can restart Nginx/PHP/MySQL via UI

---

## Phase 5: Site Management

**Goal:** Create sites with Nginx configuration.

**Estimated Time:** 3 days

**Dependencies:** Phase 4 complete

### 5.1 Site Backend

- [ ] **5.1.1** Create `SiteController` (create, store, show, update, destroy)

- [ ] **5.1.2** Create `App\Data\SiteData` DTO

- [ ] **5.1.3** Create `StoreSiteRequest`

- [ ] **5.1.4** Create `SiteResource`

- [ ] **5.1.5** Create `SitePolicy`

### 5.2 Site Creation Job

- [ ] **5.2.1** Create `App\Services\NginxConfigService`
  - Ref: `TECHNICAL_PLAN_PART2.md` → Section 5C → Site Setup

- [ ] **5.2.2** Create `App\Jobs\CreateSiteJob`
  - Create directory
  - Generate Nginx config
  - Clone repository
  - Set up deploy key

- [ ] **5.2.3** Create `App\Jobs\DeleteSiteJob`

- [ ] **5.2.4** Create `App\Actions\Sites\CreateSiteAction`

### 5.3 Site UI

- [ ] **5.3.1** Create `pages/sites/create.tsx`
  - Domain, repository, branch form

- [ ] **5.3.2** Create `pages/sites/show.tsx`
  - Tabs: Overview, Deployments, Environment, Deploy Script

- [ ] **5.3.3** Create `components/sites/site-card.tsx`

- [ ] **5.3.4** Create `components/sites/site-tabs.tsx`

- [ ] **5.3.5** Add sites list to server detail page

### 5.4 Environment Variables

- [ ] **5.4.1** Create `EnvironmentController` (index, update)

- [ ] **5.4.2** Create environment variables editor component
  - Key-value pairs editor
  - Sync to server .env file

- [ ] **5.4.3** Create `App\Jobs\SyncEnvironmentJob`

### 5.5 Deploy Scripts

- [ ] **5.5.1** Create `DeployScriptController` (show, update)

- [ ] **5.5.2** Create deploy script editor component
  - Code editor with syntax highlighting

### Milestone 5 ✓

- [ ] User can create sites on servers
- [ ] Nginx is configured automatically
- [ ] Repository is cloned
- [ ] Environment variables can be managed
- [ ] Deploy script can be customized

---

## Phase 6: Deployments

**Goal:** Trigger and monitor deployments with realtime logs.

**Estimated Time:** 3-4 days

**Dependencies:** Phase 5 complete

### 6.1 Deployment Backend

- [ ] **6.1.1** Create `DeploymentController` (index, store, show)

- [ ] **6.1.2** Create `DeploymentResource`

- [ ] **6.1.3** Create `App\Actions\Sites\TriggerDeploymentAction`

### 6.2 Deployment Job

- [ ] **6.2.1** Create `App\Jobs\DeploySiteJob`
  - Ref: `TECHNICAL_PLAN_PART2.md` → Section 6 → DeploySiteJob

- [ ] **6.2.2** Implement log streaming to database

- [ ] **6.2.3** Create `App\Events\DeploymentStatusChanged`

- [ ] **6.2.4** Create `App\Events\DeploymentOutput`

### 6.3 Realtime Logs

- [ ] **6.3.1** Set up deployment broadcasting channel

- [ ] **6.3.2** Create `hooks/use-deployment-logs.ts`
  - Ref: `TECHNICAL_PLAN_PART4.md` → Section 11 → Frontend Integration

- [ ] **6.3.3** Handle WebSocket reconnection gracefully

### 6.4 Deployment UI

- [ ] **6.4.1** Create `components/deployments/deployment-card.tsx`

- [ ] **6.4.2** Create `components/deployments/deployment-list.tsx`

- [ ] **6.4.3** Create `pages/deployments/show.tsx`
  - Realtime log viewer
  - Ref: `TECHNICAL_PLAN_PART4.md` → Section 10 → Deployment Detail

- [ ] **6.4.4** Create `components/deployments/deployment-log.tsx`
  - Terminal-style output
  - Auto-scroll
  - Color-coded errors

### 6.5 Webhooks

- [ ] **6.5.1** Create `WebhookController` for GitHub

- [ ] **6.5.2** Create `WebhookController` for GitLab

- [ ] **6.5.3** Implement signature verification
  - Ref: `TECHNICAL_PLAN_PART4.md` → Section 12 → Webhook Security

- [ ] **6.5.4** Add webhook URL display to site settings

- [ ] **6.5.5** Implement auto-deploy toggle

### 6.6 API Deployment Trigger

- [ ] **6.6.1** Create API route for deployment trigger
  - `POST /api/deploy/{site}`

- [ ] **6.6.2** Generate and display API tokens

### Milestone 6 ✓

- [ ] User can trigger deployments manually
- [ ] Deployment logs stream in realtime
- [ ] GitHub webhook triggers auto-deploy
- [ ] Deployment history is tracked
- [ ] Failed deployments show errors clearly

---

## Phase 7: Polish & Testing

**Goal:** Production-ready quality with comprehensive tests.

**Estimated Time:** 3-4 days

**Dependencies:** Phase 6 complete

### 7.1 Error Handling

- [ ] **7.1.1** Add proper error pages (404, 500, 403)

- [ ] **7.1.2** Handle SSH connection failures gracefully

- [ ] **7.1.3** Handle provider API errors with user feedback

- [ ] **7.1.4** Add retry logic where appropriate

### 7.2 UI Polish

- [ ] **7.2.1** Add loading states to all async actions

- [ ] **7.2.2** Add toast notifications for success/error

- [ ] **7.2.3** Improve mobile responsiveness

- [ ] **7.2.4** Add empty states for lists

- [ ] **7.2.5** Review and polish all forms

### 7.3 Dashboard

- [ ] **7.3.1** Create `DashboardController`

- [ ] **7.3.2** Create `pages/dashboard.tsx`
  - Server count, site count, deployment stats
  - Recent deployments
  - Server status overview

### 7.4 Feature Tests

- [ ] **7.4.1** Test server provisioning flow
  - Mock provider API
  - Mock SSH connections

- [ ] **7.4.2** Test deployment flow

- [ ] **7.4.3** Test SSH key management

- [ ] **7.4.4** Test webhook signature verification

- [ ] **7.4.5** Test authorization policies

### 7.5 Unit Tests

- [ ] **7.5.1** Test `KeyGenerator`

- [ ] **7.5.2** Test `DigitalOceanProvider`

- [ ] **7.5.3** Test `NginxConfigService`

- [ ] **7.5.4** Test `ProvisioningScriptService`

### 7.6 Security Review

- [ ] **7.6.1** Verify all secrets are encrypted

- [ ] **7.6.2** Verify authorization on all routes

- [ ] **7.6.3** Enable rate limiting

- [ ] **7.6.4** Review CSRF protection

### 7.7 Documentation

- [ ] **7.7.1** Update README with setup instructions

- [ ] **7.7.2** Document environment variables

- [ ] **7.7.3** Create deployment guide

### Milestone 7 ✓

- [ ] All tests pass
- [ ] Error handling is comprehensive
- [ ] UI is polished and responsive
- [ ] Security measures are in place
- [ ] Documentation is complete

---

## 🎉 MVP Complete Checklist

Before considering MVP done, verify:

- [ ] User can register and login
- [ ] User can connect DigitalOcean account
- [ ] User can provision a server (full stack installs)
- [ ] User can add and sync SSH keys
- [ ] User can create sites with Nginx config
- [ ] User can trigger deployments
- [ ] Deployment logs stream in realtime
- [ ] GitHub webhooks trigger auto-deploy
- [ ] Environment variables can be managed
- [ ] Deploy scripts can be customized
- [ ] All core paths have tests
- [ ] Error handling is user-friendly

---

## Notes & Decisions Log

Use this section to document important decisions made during implementation:

| Date | Decision | Reason |
|------|----------|--------|
| 2026-01-14 | Used Laravel React Starter Kit | Provides auth, Inertia, React, Shadcn UI out of the box |
| 2026-01-14 | Enums use TitleCase for cases (e.g., `Pending` not `PENDING`) | Follows Laravel/PHP conventions for backed enums |
| 2026-01-14 | Added `color()` and `label()` methods to enums | Makes it easy to render status badges in the UI |

---

## Blockers & Issues

Track any blockers here:

| Issue | Status | Resolution |
|-------|--------|------------|
| | | |
