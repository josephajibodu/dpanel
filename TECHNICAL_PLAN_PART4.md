# Laravel Forge MVP Clone - Technical Plan (Part 4)

*Continuation of TECHNICAL_PLAN_PART3.md*

---

## 10. UI Screens (Inertia + React + Shadcn)

### Screen Inventory

| Screen | Route | Purpose |
|--------|-------|---------|
| Login | `/login` | User authentication |
| Register | `/register` | New user signup |
| Dashboard | `/dashboard` | Overview stats |
| Provider Accounts List | `/provider-accounts` | Manage cloud providers |
| Provider Account Create | `/provider-accounts/create` | Add provider |
| Servers List | `/servers` | All user's servers |
| Server Create | `/servers/create` | Provision new server |
| Server Detail | `/servers/{id}` | Server overview + sites |
| SSH Keys List | `/ssh-keys` | Manage SSH keys |
| Site Create | `/servers/{id}/sites/create` | Add site to server |
| Site Detail | `/sites/{id}` | Site settings + deployments |
| Deployment Detail | `/deployments/{id}` | Logs + status |
| Environment Editor | `/sites/{id}/environment` | Edit .env |
| Deploy Script Editor | `/sites/{id}/deploy-script` | Edit deploy script |
| Settings | `/settings` | User profile + preferences |

### Screen Specifications

#### 1. Dashboard (`/dashboard`)

**Purpose:** Quick overview of infrastructure status

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│ Header: Logo | Dashboard | Servers | SSH Keys | Settings | User │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │  Servers    │  │    Sites    │  │ Deployments │             │
│  │     12      │  │     34      │  │   156 (7d)  │             │
│  └─────────────┘  └─────────────┘  └─────────────┘             │
│                                                                 │
│  Recent Deployments                                             │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ myapp.com         │ ✓ Finished │ 45s │ 2 min ago       │   │
│  │ api.example.com   │ ✓ Finished │ 32s │ 15 min ago      │   │
│  │ staging.app.com   │ ✗ Failed   │ 12s │ 1 hour ago      │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  Server Status                                                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ production-1    │ 🟢 Active │ NYC1 │ 3 sites          │   │
│  │ staging-1       │ 🟢 Active │ SFO1 │ 2 sites          │   │
│  │ new-server      │ 🟡 Provisioning (45%)               │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Components:**

- `StatCard` - Display metric with label
- `RecentDeploymentsList` - Table of recent deployments
- `ServerStatusList` - Servers with status indicators
- `ProvisioningProgress` - Progress bar for servers being provisioned

#### 2. Servers List (`/servers`)

**Purpose:** View and manage all servers

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│ Servers                                        [+ Create Server]│
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ 🖥️ production-1                                         │   │
│  │ DigitalOcean • NYC1 • 2 vCPU / 4GB                      │   │
│  │ 165.232.xx.xx • 5 sites                                 │   │
│  │ Status: 🟢 Active                                       │   │
│  │ [View] [Restart ▼] [Delete]                             │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ 🖥️ staging-1                                            │   │
│  │ Hetzner • fsn1 • 2 vCPU / 2GB                           │   │
│  │ 116.203.xx.xx • 2 sites                                 │   │
│  │ Status: 🟢 Active                                       │   │
│  │ [View] [Restart ▼] [Delete]                             │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Components:**

- `ServerCard` - Server summary with actions
- `StatusBadge` - Color-coded status indicator
- `DropdownMenu` - Restart service options

#### 3. Server Create (`/servers/create`)

**Purpose:** Provision a new server

**Form Fields:**

```
┌─────────────────────────────────────────────────────────────────┐
│ Create Server                                                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Provider Account *                                             │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ Select provider account...                         ▼    │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  Server Name *                                                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ my-server                                               │   │
│  └─────────────────────────────────────────────────────────┘   │
│  Only letters, numbers, and hyphens                            │
│                                                                 │
│  Region *                                                       │
│  ○ NYC1 - New York 1     ○ SFO1 - San Francisco 1             │
│  ○ AMS3 - Amsterdam 3    ○ SGP1 - Singapore 1                 │
│                                                                 │
│  Server Size *                                                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ ○ 1 vCPU / 1GB RAM / 25GB SSD    $6/mo                  │   │
│  │ ● 2 vCPU / 4GB RAM / 80GB SSD    $24/mo                 │   │
│  │ ○ 4 vCPU / 8GB RAM / 160GB SSD   $48/mo                 │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  PHP Version                                                    │
│  ○ PHP 8.1    ● PHP 8.3    ○ PHP 8.2                          │
│                                                                 │
│  Database                                                       │
│  ● MySQL 8.0    ○ PostgreSQL 15    ○ MariaDB 10.11            │
│                                                                 │
│                                          [Cancel] [Create Server]│
└─────────────────────────────────────────────────────────────────┘
```

**Validation:**

- Name: Required, alphanumeric + hyphens, max 255
- Provider account: Required, must be valid
- Region: Required, must be available
- Size: Required, must be available

#### 4. Server Detail (`/servers/{id}`)

**Purpose:** View server details, manage sites, perform actions

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│ ← Back to Servers                                               │
│                                                                 │
│ production-1                                    🟢 Active       │
│ DigitalOcean • NYC1 • 165.232.xx.xx                            │
├─────────────────────────────────────────────────────────────────┤
│ [Overview] [Sites (5)] [Actions] [SSH Keys]                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Server Information                                             │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ IP Address:      165.232.xx.xx    [Copy]                │   │
│  │ SSH Command:     ssh forge@165.232.xx.xx   [Copy]       │   │
│  │ PHP Version:     8.3                                    │   │
│  │ Database:        MySQL 8.0                              │   │
│  │ Provisioned:     Jan 10, 2026                           │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  Quick Actions                                                  │
│  [Restart Nginx] [Restart PHP] [Restart MySQL] [Reboot Server] │
│                                                                 │
│  Sites on this Server                       [+ Add Site]        │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ myapp.com             │ 🟢 Deployed │ [Deploy] [View]   │   │
│  │ api.myapp.com         │ 🟢 Deployed │ [Deploy] [View]   │   │
│  │ staging.myapp.com     │ 🟡 Deploying│ [View]            │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  Recent Actions                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ Restart PHP-FPM       │ ✓ Completed │ 2 min ago        │   │
│  │ Restart Nginx         │ ✓ Completed │ 5 min ago        │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

#### 5. Site Detail (`/sites/{id}`)

**Purpose:** Manage site settings and deployments

**Tabs:**

- **Overview** - Domain, repo, branch, status
- **Deployments** - Deployment history with logs
- **Environment** - .env editor
- **Deploy Script** - Customize deployment
- **Settings** - Auto-deploy, delete site

**Layout (Deployments Tab):**

```
┌─────────────────────────────────────────────────────────────────┐
│ ← Back to Server                                                │
│                                                                 │
│ myapp.com                                                       │
│ github.com/user/myapp • main                                   │
├─────────────────────────────────────────────────────────────────┤
│ [Overview] [Deployments] [Environment] [Deploy Script] [Settings]│
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ Deployments                                    [Deploy Now]     │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐│
│ │ #156                                                        ││
│ │ ✓ Finished • 45 seconds • Jan 14, 2026 10:00 AM            ││
│ │ a1b2c3d: Fix authentication bug (John Doe)                 ││
│ │ Triggered: Manual                           [View Logs]     ││
│ └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐│
│ │ #155                                                        ││
│ │ ✗ Failed • 12 seconds • Jan 14, 2026 09:30 AM              ││
│ │ b2c3d4e: Add new feature (Jane Doe)                        ││
│ │ Triggered: Webhook                          [View Logs]     ││
│ └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│ [Load More]                                                     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

#### 6. Deployment Detail (`/deployments/{id}`)

**Purpose:** View deployment logs in realtime

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│ ← Back to Site                                                  │
│                                                                 │
│ Deployment #156                                                 │
│ myapp.com                                                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│ Status: ✓ Finished                                              │
│ Duration: 45 seconds                                            │
│ Commit: a1b2c3d - Fix authentication bug                        │
│ Author: John Doe                                                │
│ Triggered by: Manual                                            │
│                                                                 │
│ Deployment Log                                                  │
│ ┌─────────────────────────────────────────────────────────────┐│
│ │ [10:00:00] Connecting to server...                          ││
│ │ [10:00:01] Running: cd /home/forge/myapp.com                ││
│ │ [10:00:01] Running: git pull origin main                    ││
│ │ [10:00:03] Already up to date.                              ││
│ │ [10:00:03] Running: composer install --no-dev               ││
│ │ [10:00:15] Installing dependencies from lock file           ││
│ │ [10:00:25] Generating optimized autoload files              ││
│ │ [10:00:26] Running: npm ci                                  ││
│ │ [10:00:35] added 1245 packages in 9s                        ││
│ │ [10:00:35] Running: npm run build                           ││
│ │ [10:00:42] vite v5.0.0 building for production...           ││
│ │ [10:00:44] ✓ built in 2.3s                                  ││
│ │ [10:00:44] Running: php artisan migrate --force             ││
│ │ [10:00:45] Nothing to migrate.                              ││
│ │ [10:00:45] ✓ Deployment finished successfully               ││
│ └─────────────────────────────────────────────────────────────┘│
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Features:**

- Auto-scroll to bottom during active deployment
- Color-coded output (errors in red)
- Live updates via WebSocket

#### 7. SSH Keys (`/ssh-keys`)

**Purpose:** Manage SSH public keys

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│ SSH Keys                                          [+ Add Key]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ 🔑 MacBook Pro                                           │   │
│  │ SHA256:abc123...                                        │   │
│  │ Added: Jan 5, 2026                                      │   │
│  │ Synced to: production-1, staging-1                      │   │
│  │ [Sync to Servers] [Delete]                              │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ 🔑 Work Desktop                                          │   │
│  │ SHA256:def456...                                        │   │
│  │ Added: Jan 8, 2026                                      │   │
│  │ Synced to: production-1                                 │   │
│  │ [Sync to Servers] [Delete]                              │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Add Key Modal:**

```
┌─────────────────────────────────────────────────────────────────┐
│ Add SSH Key                                              [X]    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Name *                                                         │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ My MacBook Pro                                          │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  Public Key *                                                   │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ ssh-ed25519 AAAA...                                     │   │
│  │                                                         │   │
│  │                                                         │   │
│  └─────────────────────────────────────────────────────────┘   │
│  Paste your public key (id_ed25519.pub or id_rsa.pub)          │
│                                                                 │
│                                          [Cancel] [Add Key]     │
└─────────────────────────────────────────────────────────────────┘
```

### React Component Structure

```
src/
├── components/
│   ├── ui/                    # Shadcn components
│   │   ├── button.tsx
│   │   ├── card.tsx
│   │   ├── dialog.tsx
│   │   ├── dropdown-menu.tsx
│   │   ├── form.tsx
│   │   ├── input.tsx
│   │   ├── select.tsx
│   │   ├── table.tsx
│   │   ├── tabs.tsx
│   │   ├── textarea.tsx
│   │   └── toast.tsx
│   │
│   ├── layout/
│   │   ├── app-layout.tsx
│   │   ├── sidebar.tsx
│   │   ├── header.tsx
│   │   └── mobile-nav.tsx
│   │
│   ├── servers/
│   │   ├── server-card.tsx
│   │   ├── server-form.tsx
│   │   ├── server-status-badge.tsx
│   │   ├── provision-progress.tsx
│   │   └── restart-dropdown.tsx
│   │
│   ├── sites/
│   │   ├── site-card.tsx
│   │   ├── site-form.tsx
│   │   ├── site-tabs.tsx
│   │   └── deployment-list.tsx
│   │
│   ├── deployments/
│   │   ├── deployment-card.tsx
│   │   ├── deployment-log.tsx
│   │   └── deployment-status.tsx
│   │
│   ├── ssh-keys/
│   │   ├── ssh-key-card.tsx
│   │   ├── add-key-dialog.tsx
│   │   └── sync-servers-dialog.tsx
│   │
│   └── shared/
│       ├── copy-button.tsx
│       ├── loading-spinner.tsx
│       ├── empty-state.tsx
│       └── confirm-dialog.tsx
│
├── pages/
│   ├── auth/
│   │   ├── login.tsx
│   │   └── register.tsx
│   │
│   ├── dashboard.tsx
│   │
│   ├── provider-accounts/
│   │   ├── index.tsx
│   │   └── create.tsx
│   │
│   ├── servers/
│   │   ├── index.tsx
│   │   ├── create.tsx
│   │   └── show.tsx
│   │
│   ├── sites/
│   │   ├── create.tsx
│   │   └── show.tsx
│   │
│   ├── deployments/
│   │   └── show.tsx
│   │
│   ├── ssh-keys/
│   │   └── index.tsx
│   │
│   └── settings/
│       └── index.tsx
│
├── hooks/
│   ├── use-echo.ts           # WebSocket subscription
│   ├── use-polling.ts        # Fallback polling
│   └── use-clipboard.ts
│
├── lib/
│   ├── utils.ts
│   └── types.ts
│
└── app.tsx
```

---

## 11. Realtime Features

### Technology: Laravel Reverb + Echo

Laravel Reverb provides a first-party WebSocket server for Laravel applications. Combined with Laravel Echo on the frontend, it enables realtime updates.

### Setup

```bash
# Install Reverb
composer require laravel/reverb
php artisan reverb:install

# Install Echo
npm install laravel-echo pusher-js
```

```typescript
// resources/js/echo.ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

### Broadcasting Channels

```php
// routes/channels.php

use App\Models\Deployment;
use App\Models\Server;

// Private channel for server updates (user-specific)
Broadcast::channel('servers.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Private channel for deployment logs
Broadcast::channel('deployments.{deploymentId}', function ($user, $deploymentId) {
    $deployment = Deployment::find($deploymentId);
    return $deployment && $deployment->site->server->user_id === $user->id;
});

// Private channel for server-specific events
Broadcast::channel('server.{serverId}', function ($user, $serverId) {
    $server = Server::find($serverId);
    return $server && $server->user_id === $user->id;
});
```

### Events

#### ServerStatusChanged

```php
// App\Events\ServerStatusChanged.php

class ServerStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    
    public function __construct(
        public Server $server,
    ) {}
    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("servers.{$this->server->user_id}"),
        ];
    }
    
    public function broadcastWith(): array
    {
        return [
            'server' => [
                'id' => $this->server->ulid,
                'status' => $this->server->status->value,
                'ip_address' => $this->server->ip_address,
            ],
        ];
    }
}
```

#### DeploymentStatusChanged

```php
// App\Events\DeploymentStatusChanged.php

class DeploymentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    
    public function __construct(
        public Deployment $deployment,
        public string $event, // started, finished, failed
    ) {}
    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("deployments.{$this->deployment->id}"),
            new PrivateChannel("server.{$this->deployment->site->server_id}"),
        ];
    }
    
    public function broadcastWith(): array
    {
        return [
            'deployment' => [
                'id' => $this->deployment->ulid,
                'status' => $this->deployment->status->value,
                'event' => $this->event,
            ],
        ];
    }
}
```

#### DeploymentOutput

```php
// App\Events\DeploymentOutput.php

class DeploymentOutput implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    
    public function __construct(
        public Deployment $deployment,
        public string $line,
        public string $type = 'output',
    ) {}
    
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("deployments.{$this->deployment->id}"),
        ];
    }
    
    public function broadcastAs(): string
    {
        return 'output';
    }
    
    public function broadcastWith(): array
    {
        return [
            'line' => $this->line,
            'type' => $this->type,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
```

### Frontend Integration

```tsx
// hooks/use-deployment-logs.ts
import { useEffect, useState } from 'react';

interface LogLine {
    line: string;
    type: 'output' | 'error' | 'info';
    timestamp: string;
}

export function useDeploymentLogs(deploymentId: string, initialLogs: LogLine[] = []) {
    const [logs, setLogs] = useState<LogLine[]>(initialLogs);
    const [status, setStatus] = useState<string>('pending');
    
    useEffect(() => {
        const channel = window.Echo.private(`deployments.${deploymentId}`);
        
        // Listen for new log lines
        channel.listen('.output', (event: LogLine) => {
            setLogs(prev => [...prev, event]);
        });
        
        // Listen for status changes
        channel.listen('DeploymentStatusChanged', (event: any) => {
            setStatus(event.deployment.status);
        });
        
        return () => {
            channel.stopListening('.output');
            channel.stopListening('DeploymentStatusChanged');
            window.Echo.leave(`deployments.${deploymentId}`);
        };
    }, [deploymentId]);
    
    return { logs, status };
}

// Usage in component
function DeploymentLog({ deployment }: { deployment: Deployment }) {
    const { logs, status } = useDeploymentLogs(
        deployment.id,
        deployment.logs ?? []
    );
    
    const logRef = useRef<HTMLDivElement>(null);
    
    // Auto-scroll to bottom
    useEffect(() => {
        if (logRef.current) {
            logRef.current.scrollTop = logRef.current.scrollHeight;
        }
    }, [logs]);
    
    return (
        <div className="space-y-4">
            <StatusBadge status={status} />
            
            <div 
                ref={logRef}
                className="bg-zinc-900 text-zinc-100 p-4 rounded-lg font-mono text-sm h-[500px] overflow-auto"
            >
                {logs.map((log, i) => (
                    <div 
                        key={i}
                        className={cn(
                            log.type === 'error' && 'text-red-400',
                            log.type === 'info' && 'text-blue-400',
                        )}
                    >
                        <span className="text-zinc-500">[{formatTime(log.timestamp)}]</span>
                        {' '}{log.line}
                    </div>
                ))}
                
                {status === 'running' && (
                    <div className="animate-pulse">▋</div>
                )}
            </div>
        </div>
    );
}
```

### Server Provisioning Progress

```tsx
// hooks/use-server-status.ts
export function useServerStatus(serverId: string, initialStatus: string) {
    const [status, setStatus] = useState(initialStatus);
    const [ipAddress, setIpAddress] = useState<string | null>(null);
    
    useEffect(() => {
        // Only subscribe if server is not yet active
        if (status === 'active') return;
        
        const channel = window.Echo.private(`server.${serverId}`);
        
        channel.listen('ServerStatusChanged', (event: any) => {
            setStatus(event.server.status);
            if (event.server.ip_address) {
                setIpAddress(event.server.ip_address);
            }
        });
        
        return () => {
            window.Echo.leave(`server.${serverId}`);
        };
    }, [serverId, status]);
    
    return { status, ipAddress };
}
```

### Polling Fallback

For reliability, implement polling as a fallback when WebSockets are unavailable:

```tsx
// hooks/use-polling.ts
export function usePolling<T>(
    fetcher: () => Promise<T>,
    interval: number = 3000,
    enabled: boolean = true,
) {
    const [data, setData] = useState<T | null>(null);
    
    useEffect(() => {
        if (!enabled) return;
        
        const poll = async () => {
            try {
                const result = await fetcher();
                setData(result);
            } catch (error) {
                console.error('Polling error:', error);
            }
        };
        
        poll(); // Initial fetch
        const intervalId = setInterval(poll, interval);
        
        return () => clearInterval(intervalId);
    }, [fetcher, interval, enabled]);
    
    return data;
}
```

---

## 12. Security Considerations

### SSH Key Security

| Aspect | Implementation |
|--------|----------------|
| **Storage** | Private keys encrypted with `APP_KEY` using Laravel's `encrypted` cast |
| **Access** | Private keys never exposed in API responses or logs |
| **Rotation** | Support key regeneration (future feature) |
| **Audit** | Log all SSH connections with timestamp and command |

### Secrets Management

```php
// All sensitive fields use encrypted casting
protected function casts(): array
{
    return [
        'credentials' => 'encrypted:array',
        'value' => 'encrypted',
    ];
}

// Environment variables are encrypted per-site
// Deploy script variables are substituted server-side only
```

### Provider API Tokens

| Concern | Mitigation |
|---------|------------|
| Storage | Encrypted in database |
| Transmission | Always over HTTPS |
| Exposure | Never logged, never in responses |
| Validation | Tokens validated before storage |

### Authentication & Authorization

```php
// Policies for authorization
class ServerPolicy
{
    public function view(User $user, Server $server): bool
    {
        return $user->id === $server->user_id;
    }
    
    public function update(User $user, Server $server): bool
    {
        return $user->id === $server->user_id;
    }
    
    public function delete(User $user, Server $server): bool
    {
        return $user->id === $server->user_id;
    }
}

// Applied in controllers
public function show(Server $server): Response
{
    $this->authorize('view', $server);
    // ...
}
```

### Rate Limiting

```php
// routes/web.php
Route::middleware(['auth', 'throttle:deployments'])->group(function () {
    Route::post('/sites/{site}/deployments', [DeploymentController::class, 'store']);
});

// App\Providers\AppServiceProvider.php
RateLimiter::for('deployments', function (Request $request) {
    // 10 deployments per minute per site
    return Limit::perMinute(10)->by($request->route('site'));
});

RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});
```

### Webhook Security

```php
// Verify GitHub webhook signature
class GitHubWebhookController
{
    public function handle(Request $request, Site $site)
    {
        $signature = $request->header('X-Hub-Signature-256');
        $payload = $request->getContent();
        
        $expectedSignature = 'sha256=' . hash_hmac(
            'sha256',
            $payload,
            $site->webhook_secret
        );
        
        if (!hash_equals($expectedSignature, $signature)) {
            abort(401, 'Invalid signature');
        }
        
        // Process webhook...
    }
}
```

### Audit Logging

```php
// App\Models\AuditLog.php
class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];
    
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }
}

// Trait for auditable models
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'created',
                'auditable_type' => get_class($model),
                'auditable_id' => $model->id,
                'new_values' => $model->toArray(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });
        
        // Similar for updated and deleted...
    }
}
```

### CSRF Protection

Inertia.js handles CSRF tokens automatically. All state-changing requests include the CSRF token.

### XSS Prevention

- React escapes all content by default
- Use `dangerouslySetInnerHTML` sparingly and only with sanitized content
- Deployment logs are displayed as text, not HTML

---

## 13. Testing Strategy

### Test Categories

| Category | Tools | Coverage Goal |
|----------|-------|---------------|
| Unit Tests | PHPUnit | Services, Actions, DTOs |
| Feature Tests | PHPUnit | Controllers, Workflows |
| Browser Tests | Playwright | Critical user paths |
| API Tests | PHPUnit | Webhook endpoints |

### Unit Tests

```php
// tests/Unit/Services/Ssh/KeyGeneratorTest.php
class KeyGeneratorTest extends TestCase
{
    public function test_generates_valid_ed25519_keypair(): void
    {
        $generator = new KeyGenerator();
        $keyPair = $generator->generate();
        
        $this->assertStringStartsWith('-----BEGIN OPENSSH PRIVATE KEY-----', $keyPair->privateKey);
        $this->assertStringStartsWith('ssh-ed25519', $keyPair->publicKey);
    }
}

// tests/Unit/Services/Providers/DigitalOceanProviderTest.php
class DigitalOceanProviderTest extends TestCase
{
    public function test_create_server_calls_correct_endpoint(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/droplets' => Http::response([
                'droplet' => [
                    'id' => 12345,
                    'name' => 'test-server',
                    'status' => 'new',
                ],
            ], 201),
        ]);
        
        $provider = new DigitalOceanProvider();
        $provider->setCredentials(['api_token' => 'test-token']);
        
        $result = $provider->createServer(
            name: 'test-server',
            size: 's-1vcpu-1gb',
            region: 'nyc1',
            sshKeyId: '123',
        );
        
        $this->assertEquals('12345', $result->id);
        Http::assertSent(fn ($request) => 
            $request->url() === 'https://api.digitalocean.com/v2/droplets' &&
            $request['name'] === 'test-server'
        );
    }
}
```

### Feature Tests

```php
// tests/Feature/ServerProvisioningTest.php
class ServerProvisioningTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_user_can_create_server(): void
    {
        $user = User::factory()->create();
        $providerAccount = ProviderAccount::factory()
            ->for($user)
            ->create(['provider' => 'digitalocean']);
        
        Http::fake([
            'api.digitalocean.com/*' => Http::response(['droplet' => ['id' => '123']], 201),
        ]);
        
        $response = $this->actingAs($user)->post('/servers', [
            'name' => 'test-server',
            'provider_account_id' => $providerAccount->id,
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'php_version' => '8.3',
            'database_type' => 'mysql',
        ]);
        
        $response->assertRedirect();
        $this->assertDatabaseHas('servers', [
            'user_id' => $user->id,
            'name' => 'test-server',
            'status' => 'pending',
        ]);
        
        Queue::assertPushed(ProvisionServerJob::class);
    }
    
    public function test_user_cannot_access_other_users_servers(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $server = Server::factory()->for($user1)->create();
        
        $response = $this->actingAs($user2)->get("/servers/{$server->ulid}");
        
        $response->assertForbidden();
    }
}

// tests/Feature/DeploymentTest.php
class DeploymentTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_deployment_creates_log_entries(): void
    {
        $site = Site::factory()->create();
        $deployment = Deployment::factory()->for($site)->create();
        
        // Simulate job execution
        $job = new DeploySiteJob($deployment);
        
        // Mock SSH
        $this->mock(SshService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturn(
                Mockery::mock(SshConnection::class, function ($mock) {
                    $mock->shouldReceive('exec')->andReturn('commit info');
                    $mock->shouldReceive('execWithOutput')
                        ->andReturnUsing(function ($cmd, $callback) {
                            $callback('Deploying...');
                            $callback('Done!');
                            return 0;
                        });
                })
            );
        });
        
        $job->handle(app(SshService::class));
        
        $this->assertEquals(2, $deployment->logs()->count());
        $this->assertEquals('finished', $deployment->fresh()->status->value);
    }
}
```

### SSH Mocking

```php
// tests/Mocks/FakeSshConnection.php
class FakeSshConnection
{
    private array $commands = [];
    private array $outputs = [];
    
    public function shouldReceive(string $command): self
    {
        $this->commands[] = $command;
        return $this;
    }
    
    public function andReturn(string $output): self
    {
        $this->outputs[] = $output;
        return $this;
    }
    
    public function exec(string $command): string
    {
        // Return configured output or empty string
        foreach ($this->commands as $i => $pattern) {
            if (str_contains($command, $pattern)) {
                return $this->outputs[$i] ?? '';
            }
        }
        return '';
    }
}

// In tests
$this->mock(SshService::class, function ($mock) use ($server) {
    $mock->shouldReceive('connect')
        ->with(Mockery::on(fn ($s) => $s->id === $server->id))
        ->andReturn(new FakeSshConnection());
});
```

### Provider API Mocking

```php
// tests/Feature/ProviderAccountTest.php
public function test_validates_digitalocean_credentials(): void
{
    Http::fake([
        'api.digitalocean.com/v2/account' => Http::response([
            'account' => ['status' => 'active'],
        ]),
    ]);
    
    $user = User::factory()->create();
    
    $response = $this->actingAs($user)->post('/provider-accounts', [
        'provider' => 'digitalocean',
        'name' => 'My DO Account',
        'credentials' => ['api_token' => 'valid-token'],
    ]);
    
    $response->assertRedirect();
    $this->assertDatabaseHas('provider_accounts', [
        'user_id' => $user->id,
        'is_valid' => true,
    ]);
}

public function test_rejects_invalid_credentials(): void
{
    Http::fake([
        'api.digitalocean.com/v2/account' => Http::response(['message' => 'Unauthorized'], 401),
    ]);
    
    $user = User::factory()->create();
    
    $response = $this->actingAs($user)->post('/provider-accounts', [
        'provider' => 'digitalocean',
        'name' => 'My DO Account',
        'credentials' => ['api_token' => 'invalid-token'],
    ]);
    
    $response->assertSessionHasErrors('credentials');
}
```

### Test Commands

```bash
# Run all tests
php artisan test

# Run with coverage
php artisan test --coverage --min=80

# Run specific suite
php artisan test --testsuite=Feature

# Run specific test
php artisan test --filter=ServerProvisioningTest
```

---

## 14. Future Roadmap

### Phase 2: Core Enhancements (3-4 months after MVP)

| Feature | Description | Priority |
|---------|-------------|----------|
| SSL Certificates | Let's Encrypt auto-provisioning via Certbot | P1 |
| Queue Workers | Supervisor-managed queue workers with Horizon dashboard | P1 |
| Scheduled Jobs | Cron job management via UI | P1 |
| Database Management | Create databases and users, run queries | P2 |
| Firewall Rules | UFW rule management via UI | P2 |

### Phase 3: Scale & Teams (6 months after MVP)

| Feature | Description | Priority |
|---------|-------------|----------|
| Teams | Multi-user accounts with role-based access | P1 |
| Activity Logs | Comprehensive audit trail with filtering | P1 |
| Server Monitoring | CPU, memory, disk metrics with alerts | P2 |
| Automated Backups | Database backups to S3/DigitalOcean Spaces | P2 |

### Phase 4: Enterprise (12 months after MVP)

| Feature | Description | Priority |
|---------|-------------|----------|
| Billing | Stripe subscriptions with usage-based pricing | P1 |
| Load Balancers | Provision and manage load balancers | P2 |
| Database Servers | Standalone database server provisioning | P2 |
| Worker Servers | Dedicated queue worker servers | P2 |
| Custom Recipes | User-defined provisioning scripts | P3 |
| API | Public API for external integrations | P2 |

### Phase 5: Advanced Features

| Feature | Description |
|---------|-------------|
| Multi-Server Deployments | Coordinate deployments across server clusters |
| Blue-Green Deployments | Zero-downtime deployments with traffic switching |
| Docker Support | Deploy containerized applications |
| Terraform Integration | Import/export infrastructure as code |
| DigitalOcean App Platform | Deploy to managed platforms |
| GitHub Actions Integration | Trigger deployments from CI/CD pipelines |

### Technical Debt & Improvements

| Item | Description |
|------|-------------|
| E2E Testing | Add Playwright tests for critical flows |
| Performance | Implement caching layer for provider API responses |
| Observability | Add OpenTelemetry tracing |
| Documentation | API documentation with OpenAPI spec |
| Internationalization | Multi-language support |

---

## Appendix A: Directory Structure

```
app/
├── Actions/
│   ├── Servers/
│   │   ├── CreateServerAction.php
│   │   └── DeleteServerAction.php
│   ├── Sites/
│   │   ├── CreateSiteAction.php
│   │   └── TriggerDeploymentAction.php
│   └── SshKeys/
│       └── SyncSshKeyAction.php
├── Contracts/
│   └── ProviderContract.php
├── Data/
│   ├── ServerData.php
│   ├── SiteData.php
│   └── DeploymentData.php
├── Enums/
│   ├── ServerStatus.php
│   ├── SiteStatus.php
│   └── DeploymentStatus.php
├── Events/
│   ├── ServerStatusChanged.php
│   ├── DeploymentStatusChanged.php
│   └── DeploymentOutput.php
├── Http/
│   ├── Controllers/
│   │   ├── DashboardController.php
│   │   ├── ProviderAccountController.php
│   │   ├── ServerController.php
│   │   ├── SiteController.php
│   │   ├── DeploymentController.php
│   │   ├── SshKeyController.php
│   │   ├── EnvironmentController.php
│   │   ├── DeployScriptController.php
│   │   └── Webhook/
│   │       ├── GitHubWebhookController.php
│   │       └── GitLabWebhookController.php
│   ├── Requests/
│   │   ├── StoreServerRequest.php
│   │   ├── StoreSiteRequest.php
│   │   └── StoreSshKeyRequest.php
│   └── Resources/
│       ├── ServerResource.php
│       ├── SiteResource.php
│       └── DeploymentResource.php
├── Jobs/
│   ├── ProvisionServerJob.php
│   ├── InstallStackJob.php
│   ├── DeleteServerJob.php
│   ├── CreateSiteJob.php
│   ├── DeploySiteJob.php
│   ├── DeleteSiteJob.php
│   ├── SyncSshKeyJob.php
│   ├── RevokeSshKeyJob.php
│   └── RestartServiceJob.php
├── Models/
│   ├── User.php
│   ├── ProviderAccount.php
│   ├── Server.php
│   ├── ServerCredential.php
│   ├── SshKey.php
│   ├── Site.php
│   ├── Deployment.php
│   ├── DeploymentLog.php
│   ├── DeployScript.php
│   ├── EnvironmentVariable.php
│   └── ServerAction.php
├── Policies/
│   ├── ServerPolicy.php
│   ├── SitePolicy.php
│   └── SshKeyPolicy.php
└── Services/
    ├── Providers/
    │   ├── ProviderManager.php
    │   ├── DigitalOceanProvider.php
    │   ├── HetznerProvider.php
    │   └── VultrProvider.php
    ├── Ssh/
    │   ├── SshService.php
    │   ├── SshConnection.php
    │   ├── KeyGenerator.php
    │   └── SshRetryHandler.php
    ├── ProvisioningScriptService.php
    ├── NginxConfigService.php
    └── DeploymentService.php

database/
├── factories/
│   ├── UserFactory.php
│   ├── ServerFactory.php
│   ├── SiteFactory.php
│   └── DeploymentFactory.php
└── migrations/
    ├── create_users_table.php
    ├── create_provider_accounts_table.php
    ├── create_servers_table.php
    ├── create_server_credentials_table.php
    ├── create_ssh_keys_table.php
    ├── create_server_ssh_key_table.php
    ├── create_sites_table.php
    ├── create_deployments_table.php
    ├── create_deployment_logs_table.php
    ├── create_deploy_scripts_table.php
    ├── create_environment_variables_table.php
    └── create_server_actions_table.php

resources/
└── js/
    ├── app.tsx
    ├── components/
    ├── hooks/
    ├── lib/
    └── pages/

tests/
├── Feature/
│   ├── ServerProvisioningTest.php
│   ├── SiteManagementTest.php
│   ├── DeploymentTest.php
│   └── SshKeyTest.php
└── Unit/
    ├── Services/
    │   ├── KeyGeneratorTest.php
    │   └── DigitalOceanProviderTest.php
    └── Actions/
        └── CreateServerActionTest.php
```

---

## Appendix B: Environment Variables

```env
# Application
APP_NAME=ServerForge
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://serverforge.app

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=serverforge
DB_USERNAME=serverforge
DB_PASSWORD=secret

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Queue
QUEUE_CONNECTION=redis

# Session
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# Broadcasting
BROADCAST_CONNECTION=reverb

# Reverb
REVERB_APP_ID=serverforge
REVERB_APP_KEY=your-reverb-key
REVERB_APP_SECRET=your-reverb-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=https

# Frontend (Vite)
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

---

## Appendix C: Deployment Checklist

### Pre-Launch

- [ ] SSL certificate configured
- [ ] Environment variables set
- [ ] Database migrations run
- [ ] Redis connection verified
- [ ] Queue workers running (Supervisor)
- [ ] Reverb WebSocket server running
- [ ] Horizon dashboard accessible
- [ ] Provider API tokens tested
- [ ] SSH key generation working

### Security

- [ ] APP_DEBUG=false
- [ ] Secure APP_KEY generated
- [ ] HTTPS enforced
- [ ] Rate limiting enabled
- [ ] CORS configured correctly
- [ ] CSP headers set

### Monitoring

- [ ] Error tracking (Sentry/Bugsnag)
- [ ] Application metrics
- [ ] Queue monitoring (Horizon)
- [ ] Log aggregation
- [ ] Uptime monitoring

---

*End of Technical Plan*
