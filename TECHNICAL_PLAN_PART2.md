# Laravel Forge MVP Clone - Technical Plan (Part 2)

*Continuation of TECHNICAL_PLAN.md*

---

## 5. Core Workflows

### A. Server Provisioning Workflow

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         SERVER PROVISIONING FLOW                              │
└──────────────────────────────────────────────────────────────────────────────┘

USER                    FRONTEND                 BACKEND                  QUEUE
 │                         │                        │                        │
 │ 1. Fill form            │                        │                        │
 │ (name, region, size)    │                        │                        │
 ├────────────────────────>│                        │                        │
 │                         │                        │                        │
 │                         │ 2. POST /servers       │                        │
 │                         ├───────────────────────>│                        │
 │                         │                        │                        │
 │                         │                        │ 3. Validate request    │
 │                         │                        │ 4. Create Server       │
 │                         │                        │    (status: pending)   │
 │                         │                        │ 5. Generate SSH keypair│
 │                         │                        │ 6. Dispatch job        │
 │                         │                        ├───────────────────────>│
 │                         │                        │                        │
 │                         │ 7. Return server       │                        │
 │                         │<───────────────────────┤                        │
 │                         │                        │                        │
 │ 8. Redirect to          │                        │                        │
 │    server detail        │                        │                        │
 │<────────────────────────┤                        │                        │
 │                         │                        │                        │
 │ 9. Subscribe to         │                        │                        │
 │    WebSocket channel    │                        │                        │
 │                         │                        │                        │
                                                                    QUEUE WORKER
                                                                         │
                                                    │ 10. ProvisionServerJob│
                                                    │<───────────────────────┤
                                                    │                        │
                                                    │ 11. Call Provider API  │
                                                    │     (create droplet)   │
                                                    │                        │
                                                    │ 12. Update status:     │
                                                    │     'creating'         │
                                                    │                        │
                                                    │ 13. Broadcast update   │
                                                    ├────────────────────────>│
                                                    │                        │
                                                    │ 14. Poll until server  │
                                                    │     is active          │
                                                    │     (provider API)     │
                                                    │                        │
                                                    │ 15. Get IP address     │
                                                    │                        │
                                                    │ 16. Update server:     │
                                                    │     ip_address,        │
                                                    │     status: provisioning│
                                                    │                        │
                                                    │ 17. Dispatch           │
                                                    │     InstallStackJob    │
                                                    ├───────────────────────>│
                                                    │                        │
                                                                    QUEUE WORKER
                                                                         │
                                                    │ 18. InstallStackJob    │
                                                    │<───────────────────────┤
                                                    │                        │
                                                    │ 19. Wait for SSH       │
                                                    │     (retry with backoff)│
                                                    │                        │
                                                    │ 20. SSH connect        │
                                                    │                        │
                                                    │ 21. Upload provision   │
                                                    │     script             │
                                                    │                        │
                                                    │ 22. Execute script     │
                                                    │     (stream output)    │
                                                    │                        │
                                                    │ 23. Broadcast progress │
                                                    │     updates            │
                                                    ├────────────────────────>│
                                                    │                        │
                                                    │ 24. On success:        │
                                                    │     status: 'active'   │
                                                    │     provisioned_at: now│
                                                    │                        │
                                                    │ 25. Broadcast complete │
                                                    ├────────────────────────>│
                                                    │                        │
                                                    │ 26. On failure:        │
                                                    │     status: 'error'    │
                                                    │     log error          │
```

#### Provisioning Script Overview

The provisioning script is a Bash script that runs on the new server:

```bash
#!/bin/bash
set -e

export DEBIAN_FRONTEND=noninteractive

# --- System Update ---
apt-get update
apt-get upgrade -y

# --- Create forge user ---
useradd -m -s /bin/bash forge
usermod -aG sudo forge
echo "forge ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers.d/forge

# --- SSH Configuration ---
mkdir -p /home/forge/.ssh
cp /root/.ssh/authorized_keys /home/forge/.ssh/
chown -R forge:forge /home/forge/.ssh
chmod 700 /home/forge/.ssh
chmod 600 /home/forge/.ssh/authorized_keys

# --- Install Nginx ---
apt-get install -y nginx
systemctl enable nginx

# --- Install PHP 8.3 ---
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y php8.3-fpm php8.3-cli php8.3-common \
    php8.3-mysql php8.3-pgsql php8.3-sqlite3 php8.3-redis \
    php8.3-curl php8.3-gd php8.3-mbstring php8.3-xml \
    php8.3-zip php8.3-bcmath php8.3-intl php8.3-readline

# --- Install MySQL 8 ---
apt-get install -y mysql-server
mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DB_PASSWORD}';"
mysql -e "FLUSH PRIVILEGES;"

# --- OR Install PostgreSQL 15 ---
# apt-get install -y postgresql-15
# sudo -u postgres psql -c "ALTER USER postgres PASSWORD '${DB_PASSWORD}';"

# --- Install Redis ---
apt-get install -y redis-server
systemctl enable redis-server

# --- Install Composer ---
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# --- Install Node.js 20 ---
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get install -y nodejs

# --- Install Supervisor ---
apt-get install -y supervisor
systemctl enable supervisor

# --- Install Git ---
apt-get install -y git

# --- Configure Firewall ---
ufw allow 22
ufw allow 80
ufw allow 443
ufw --force enable

# --- Create sites directory ---
mkdir -p /home/forge
chown forge:forge /home/forge

# --- Cleanup ---
apt-get autoremove -y
apt-get clean

echo "Provisioning complete!"
```

### B. SSH Key Management Workflow

#### Adding a New SSH Key

```
USER                    FRONTEND                 BACKEND                  DATABASE
 │                         │                        │                        │
 │ 1. Paste public key     │                        │                        │
 │    + name               │                        │                        │
 ├────────────────────────>│                        │                        │
 │                         │                        │                        │
 │                         │ 2. POST /ssh-keys      │                        │
 │                         ├───────────────────────>│                        │
 │                         │                        │                        │
 │                         │                        │ 3. Validate key format │
 │                         │                        │    (ssh-rsa, ssh-ed25519)
 │                         │                        │                        │
 │                         │                        │ 4. Calculate fingerprint│
 │                         │                        │                        │
 │                         │                        │ 5. Check uniqueness    │
 │                         │                        │                        │
 │                         │                        │ 6. Store key           │
 │                         │                        ├───────────────────────>│
 │                         │                        │                        │
 │                         │ 7. Return key          │                        │
 │                         │<───────────────────────┤                        │
 │                         │                        │                        │
 │ 8. Show success         │                        │                        │
 │<────────────────────────┤                        │                        │
```

#### Syncing Keys to Servers

```
USER                    FRONTEND                 BACKEND                  QUEUE
 │                         │                        │                        │
 │ 1. Select servers       │                        │                        │
 │    to sync key to       │                        │                        │
 ├────────────────────────>│                        │                        │
 │                         │                        │                        │
 │                         │ 2. POST /ssh-keys/{id}/sync                     │
 │                         ├───────────────────────>│                        │
 │                         │                        │                        │
 │                         │                        │ 3. For each server:    │
 │                         │                        │    - Create pivot record│
 │                         │                        │      (status: pending)  │
 │                         │                        │    - Dispatch job      │
 │                         │                        ├───────────────────────>│
 │                         │                        │                        │
 │                         │ 4. Return accepted     │                        │
 │                         │<───────────────────────┤                        │
                                                                    QUEUE WORKER
                                                                         │
                                                    │ 5. SyncSshKeyJob       │
                                                    │<───────────────────────┤
                                                    │                        │
                                                    │ 6. SSH to server       │
                                                    │                        │
                                                    │ 7. Append to           │
                                                    │    authorized_keys     │
                                                    │                        │
                                                    │ 8. Update pivot:       │
                                                    │    status: 'synced'    │
                                                    │    synced_at: now      │
                                                    │                        │
                                                    │ 9. Broadcast update    │
```

#### Revoking Keys

```
SSH Command executed:
sed -i '/{fingerprint}/d' /home/forge/.ssh/authorized_keys
```

### C. Site Setup Workflow

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                            SITE CREATION FLOW                                 │
└──────────────────────────────────────────────────────────────────────────────┘

Step 1: Create Site Record
─────────────────────────────
- Validate domain format
- Check domain uniqueness on server
- Create site record (status: pending)
- Dispatch CreateSiteJob

Step 2: Create Nginx Configuration
─────────────────────────────────────
SSH commands:
```bash
# Create site directory
mkdir -p /home/forge/{domain}
chown forge:forge /home/forge/{domain}

# Create Nginx config
cat > /etc/nginx/sites-available/{domain} << 'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name {domain} {aliases};
    root /home/forge/{domain}{web_directory};

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php{php_version}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

# Enable site
ln -sf /etc/nginx/sites-available/{domain} /etc/nginx/sites-enabled/

# Test and reload nginx
nginx -t && systemctl reload nginx
```

Step 3: Setup Git Repository (if provided)
────────────────────────────────────────────

```bash
# Generate deploy key for this site
ssh-keygen -t ed25519 -f /home/forge/.ssh/deploy_{domain} -N ""

# Clone repository
cd /home/forge/{domain}
git clone -b {branch} {repository} .
```

Step 4: Create Deploy Script
─────────────────────────────
Create default deploy script in database.

Step 5: Initial Deployment
───────────────────────────
Trigger first deployment to set up the application.

```

### D. Deployment Workflow

```

┌──────────────────────────────────────────────────────────────────────────────┐
│                           DEPLOYMENT FLOW                                     │
└──────────────────────────────────────────────────────────────────────────────┘

TRIGGER                 BACKEND                  QUEUE                   SERVER
 │                         │                        │                        │
 │ (Manual/Webhook/API)    │                        │                        │
 ├────────────────────────>│                        │                        │
 │                         │                        │                        │
 │                         │ 1. Create deployment   │                        │
 │                         │    (status: pending)   │                        │
 │                         │                        │                        │
 │                         │ 2. Update site:        │                        │
 │                         │    deployment_started_at│                        │
 │                         │                        │                        │
 │                         │ 3. Dispatch job        │                        │
 │                         ├───────────────────────>│                        │
 │                         │                        │                        │
 │                         │ 4. Broadcast started   │                        │
 │                         │                        │                        │
                                                    │ 5. DeploySiteJob      │
                                                    │    starts             │
                                                    │                        │
                                                    │ 6. Update deployment: │
                                                    │    status: running     │
                                                    │    started_at: now     │
                                                    │                        │
                                                    │ 7. SSH connect         │
                                                    ├───────────────────────>│
                                                    │                        │
                                                    │ 8. Fetch latest commit │
                                                    │    info from git       │
                                                    │<───────────────────────┤
                                                    │                        │
                                                    │ 9. Update deployment   │
                                                    │    with commit info    │
                                                    │                        │
                                                    │ 10. Execute deploy     │
                                                    │     script line by line│
                                                    ├───────────────────────>│
                                                    │                        │
                                                    │ 11. Stream output      │
                                                    │<───────────────────────┤
                                                    │                        │
                                                    │ 12. For each line:     │
                                                    │     - Save to          │
                                                    │       deployment_logs  │
                                                    │     - Broadcast via    │
                                                    │       WebSocket        │
                                                    │                        │
                                                    │ 13. On completion:     │
                                                    │     success:           │
                                                    │       status: finished │
                                                    │     failure:           │
                                                    │       status: failed   │
                                                    │                        │
                                                    │ 14. Calculate duration │
                                                    │                        │
                                                    │ 15. Update site:       │
                                                    │     deployment_finished│
                                                    │                        │
                                                    │ 16. Broadcast complete │

```

#### Deploy Script Execution

```php
// Pseudocode for deploy script execution
public function execute(Deployment $deployment): void
{
    $site = $deployment->site;
    $server = $site->server;
    $script = $site->deployScript->script;
    
    // Replace variables
    $script = str_replace([
        '{{SITE_PATH}}',
        '{{BRANCH}}',
        '{{DOMAIN}}',
    ], [
        "/home/forge/{$site->domain}",
        $site->branch,
        $site->domain,
    ], $script);
    
    // Upload script
    $this->ssh->upload($script, '/tmp/deploy.sh');
    $this->ssh->exec('chmod +x /tmp/deploy.sh');
    
    // Execute with output streaming
    $this->ssh->exec('/tmp/deploy.sh', function ($output) use ($deployment) {
        // Save log line
        $deployment->logs()->create([
            'type' => 'output',
            'message' => $output,
        ]);
        
        // Broadcast to WebSocket
        broadcast(new DeploymentOutputReceived($deployment, $output));
    });
    
    // Cleanup
    $this->ssh->exec('rm /tmp/deploy.sh');
}
```

---

## 6. Jobs & Queues

### Job Catalog

| Job | Queue | Description | Retry | Timeout |
|-----|-------|-------------|-------|---------|
| `ProvisionServerJob` | provision | Creates server via provider API | 0 | 300s |
| `InstallStackJob` | provision | Runs provisioning script on server | 0 | 1800s |
| `DeleteServerJob` | provision | Deletes server from provider | 3 | 120s |
| `DeploySiteJob` | deploy | Runs deployment script | 0 | 600s |
| `CreateSiteJob` | deploy | Creates Nginx config and clones repo | 0 | 300s |
| `DeleteSiteJob` | deploy | Removes site from server | 3 | 120s |
| `SyncSshKeyJob` | ssh | Adds SSH key to server | 3 | 60s |
| `RevokeSshKeyJob` | ssh | Removes SSH key from server | 3 | 60s |
| `RestartServiceJob` | ssh | Restarts a service on server | 3 | 60s |
| `ExecuteCommandJob` | ssh | Runs arbitrary command | 0 | 300s |
| `ValidateProviderJob` | default | Validates provider API token | 3 | 30s |

### Job Details

#### `ProvisionServerJob`

```php
class ProvisionServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public int $timeout = 300;
    public int $tries = 1; // No retries - creates duplicate servers
    public bool $deleteWhenMissingModels = true;
    
    public function __construct(
        public Server $server,
    ) {}
    
    public function handle(
        ProviderManager $providers,
    ): void {
        // Update status
        $this->server->update(['status' => ServerStatus::CREATING]);
        $this->broadcast();
        
        // Get provider instance
        $provider = $providers->driver($this->server->provider);
        
        // Create server at provider
        $result = $provider->createServer(
            name: $this->server->name,
            size: $this->server->size,
            region: $this->server->region,
            sshKeyId: $this->getSshKeyId($provider),
        );
        
        // Store provider's server ID
        $this->server->update([
            'provider_server_id' => $result->id,
        ]);
        
        // Poll until active
        $maxAttempts = 60;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            $status = $provider->getServerStatus($result->id);
            
            if ($status->isActive()) {
                $this->server->update([
                    'ip_address' => $status->ipAddress,
                    'private_ip_address' => $status->privateIpAddress,
                    'status' => ServerStatus::PROVISIONING,
                ]);
                $this->broadcast();
                
                // Dispatch next job
                InstallStackJob::dispatch($this->server)
                    ->delay(now()->addSeconds(30)); // Wait for SSH to be ready
                    
                return;
            }
            
            $attempt++;
            sleep(5);
        }
        
        throw new ServerProvisioningException('Server did not become active in time');
    }
    
    public function failed(Throwable $exception): void
    {
        $this->server->update([
            'status' => ServerStatus::ERROR,
        ]);
        
        // Log error for user visibility
        $this->server->actions()->create([
            'action' => 'provision',
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ]);
        
        $this->broadcast();
    }
    
    private function broadcast(): void
    {
        broadcast(new ServerStatusChanged($this->server));
    }
}
```

#### `DeploySiteJob`

```php
class DeploySiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public int $timeout = 600;
    public int $tries = 1;
    public bool $deleteWhenMissingModels = true;
    
    public function __construct(
        public Deployment $deployment,
    ) {}
    
    public function handle(SshService $ssh): void
    {
        $site = $this->deployment->site;
        $server = $site->server;
        
        // Update status
        $this->deployment->update([
            'status' => DeploymentStatus::RUNNING,
            'started_at' => now(),
        ]);
        $this->broadcast('started');
        
        try {
            // Connect to server
            $connection = $ssh->connect($server);
            
            // Get current commit info
            $commitInfo = $connection->exec(
                "cd /home/forge/{$site->domain} && git rev-parse HEAD && git log -1 --format='%s' && git log -1 --format='%an'"
            );
            
            $lines = explode("\n", trim($commitInfo));
            $this->deployment->update([
                'commit_hash' => $lines[0] ?? null,
                'commit_message' => Str::limit($lines[1] ?? '', 255),
                'commit_author' => $lines[2] ?? null,
            ]);
            
            // Get deploy script
            $script = $site->deployScript?->script ?? $this->getDefaultScript($site);
            
            // Execute script with streaming output
            $connection->execWithOutput(
                $this->prepareScript($script, $site),
                fn (string $line) => $this->logOutput($line),
            );
            
            // Success
            $this->deployment->update([
                'status' => DeploymentStatus::FINISHED,
                'finished_at' => now(),
                'duration_seconds' => now()->diffInSeconds($this->deployment->started_at),
            ]);
            
            $site->update([
                'status' => SiteStatus::DEPLOYED,
                'deployment_finished_at' => now(),
            ]);
            
            $this->broadcast('finished');
            
        } catch (Throwable $e) {
            $this->logOutput("[ERROR] {$e->getMessage()}", 'error');
            throw $e;
        }
    }
    
    public function failed(Throwable $exception): void
    {
        $this->deployment->update([
            'status' => DeploymentStatus::FAILED,
            'finished_at' => now(),
        ]);
        
        $this->deployment->site->update([
            'status' => SiteStatus::FAILED,
        ]);
        
        $this->broadcast('failed');
    }
    
    private function logOutput(string $line, string $type = 'output'): void
    {
        $this->deployment->logs()->create([
            'type' => $type,
            'message' => $line,
        ]);
        
        broadcast(new DeploymentOutput(
            $this->deployment,
            $line,
            $type,
        ));
    }
    
    private function broadcast(string $event): void
    {
        broadcast(new DeploymentStatusChanged($this->deployment, $event));
    }
}
```

### Queue Configuration

```php
// config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 1900, // Longer than longest job
        'block_for' => null,
        'after_commit' => true,
    ],
],

// config/horizon.php (if using Horizon)
'defaults' => [
    'supervisor-1' => [
        'connection' => 'redis',
        'queue' => ['provision', 'deploy', 'ssh', 'default'],
        'balance' => 'auto',
        'autoScalingStrategy' => 'time',
        'maxProcesses' => 10,
        'maxTime' => 0,
        'maxJobs' => 0,
        'memory' => 128,
        'tries' => 1,
        'timeout' => 1800,
    ],
],

'environments' => [
    'production' => [
        'supervisor-provision' => [
            'queue' => ['provision'],
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
        ],
        'supervisor-deploy' => [
            'queue' => ['deploy'],
            'minProcesses' => 2,
            'maxProcesses' => 5,
        ],
        'supervisor-ssh' => [
            'queue' => ['ssh', 'default'],
            'minProcesses' => 2,
            'maxProcesses' => 10,
        ],
    ],
],
```

---

*Continued in TECHNICAL_PLAN_PART3.md*
