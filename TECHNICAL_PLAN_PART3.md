# Laravel Forge MVP Clone - Technical Plan (Part 3)

*Continuation of TECHNICAL_PLAN_PART2.md*

---

## 7. SSH Architecture

### Overview

The SSH subsystem is **critical infrastructure** for ServerForge. Every server management operation—provisioning, deployments, key synchronization, service restarts—depends on reliable SSH connectivity.

### Technology Choice: phpseclib3

We use **phpseclib3** (PHP Secure Communications Library) for SSH operations:

```bash
composer require phpseclib/phpseclib:~3.0
```

**Why phpseclib3:**

- Pure PHP implementation (no ext-ssh2 required)
- Active maintenance and security updates
- Supports modern key types (Ed25519)
- Stream-based output for realtime logs
- Configurable timeouts and keep-alives

### SSH Key Management

#### Key Generation and Storage

```php
// App\Services\Ssh\KeyGenerator.php

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;

class KeyGenerator
{
    /**
     * Generate an Ed25519 keypair for server authentication.
     * Ed25519 is preferred for:
     * - Smaller key size (256-bit vs 4096-bit RSA)
     * - Faster operations
     * - Better security properties
     */
    public function generate(): KeyPair
    {
        $privateKey = EC::createKey('Ed25519');
        
        return new KeyPair(
            privateKey: $privateKey->toString('OpenSSH'),
            publicKey: $privateKey->getPublicKey()->toString('OpenSSH'),
        );
    }
    
    /**
     * Generate RSA keypair for legacy compatibility.
     */
    public function generateRsa(int $bits = 4096): KeyPair
    {
        $privateKey = RSA::createKey($bits);
        
        return new KeyPair(
            privateKey: $privateKey->toString('OpenSSH'),
            publicKey: $privateKey->getPublicKey()->toString('OpenSSH'),
        );
    }
}

// DTOs/KeyPair.php
readonly class KeyPair
{
    public function __construct(
        public string $privateKey,
        public string $publicKey,
    ) {}
}
```

#### Key Storage (Encryption)

All private keys are encrypted at rest using Laravel's encryption:

```php
// App\Models\Server.php

protected function casts(): array
{
    return [
        'sudo_password' => 'encrypted',
        'database_password' => 'encrypted',
    ];
}

// Server private key stored in separate table for security
// App\Models\ServerCredential.php

class ServerCredential extends Model
{
    protected $fillable = ['server_id', 'type', 'value'];
    
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
    
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}

// Usage
$server->credential()->updateOrCreate(
    ['type' => 'private_key'],
    ['value' => $keyPair->privateKey]
);
```

**Security Notes:**

- Private keys are **never** exposed via API responses
- Encryption key (`APP_KEY`) should be stored in secure vault in production
- Consider using AWS KMS or HashiCorp Vault for key management at scale

### SSH Connection Service

```php
// App\Services\Ssh\SshService.php

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class SshService
{
    private const DEFAULT_TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;
    
    public function connect(Server $server): SshConnection
    {
        $ssh = new SSH2(
            host: $server->ip_address,
            port: $server->ssh_port,
            timeout: self::CONNECT_TIMEOUT,
        );
        
        // Configure connection
        $ssh->setKeepAlive(30); // Send keep-alive every 30s
        
        // Load private key
        $privateKey = $server->credential()
            ->where('type', 'private_key')
            ->firstOrFail()
            ->value;
            
        $key = PublicKeyLoader::load($privateKey);
        
        // Authenticate
        if (!$ssh->login('forge', $key)) {
            // Fallback to root during provisioning
            if (!$ssh->login('root', $key)) {
                throw new SshConnectionException(
                    "Failed to authenticate to {$server->ip_address}"
                );
            }
        }
        
        // Update last connection timestamp
        $server->touch('last_ssh_connection_at');
        
        return new SshConnection($ssh, $server);
    }
    
    public function testConnection(Server $server): bool
    {
        try {
            $connection = $this->connect($server);
            $result = $connection->exec('echo "ok"');
            $connection->disconnect();
            
            return trim($result) === 'ok';
        } catch (Throwable) {
            return false;
        }
    }
}
```

### SSH Connection Wrapper

```php
// App\Services\Ssh\SshConnection.php

class SshConnection
{
    public function __construct(
        private SSH2 $ssh,
        private Server $server,
    ) {}
    
    /**
     * Execute command and return output.
     */
    public function exec(string $command, int $timeout = 30): string
    {
        $this->ssh->setTimeout($timeout);
        
        $output = $this->ssh->exec($command);
        
        if ($this->ssh->getExitStatus() !== 0) {
            throw new SshCommandException(
                command: $command,
                exitCode: $this->ssh->getExitStatus(),
                output: $output,
                stderr: $this->ssh->getStdError(),
            );
        }
        
        return $output;
    }
    
    /**
     * Execute command with streaming output callback.
     * Used for deployment logs and provisioning progress.
     */
    public function execWithOutput(
        string $command,
        callable $onOutput,
        int $timeout = 600,
    ): int {
        $this->ssh->setTimeout($timeout);
        
        // Use exec with callback for streaming
        $this->ssh->exec($command, function ($output) use ($onOutput) {
            // Split into lines and call callback for each
            $lines = explode("\n", $output);
            foreach ($lines as $line) {
                if ($line !== '') {
                    $onOutput($line);
                }
            }
        });
        
        return $this->ssh->getExitStatus();
    }
    
    /**
     * Upload file content to server.
     */
    public function upload(string $content, string $remotePath): void
    {
        $sftp = $this->ssh->getSFTP();
        
        if (!$sftp->put($remotePath, $content)) {
            throw new SshUploadException("Failed to upload to {$remotePath}");
        }
    }
    
    /**
     * Download file content from server.
     */
    public function download(string $remotePath): string
    {
        $sftp = $this->ssh->getSFTP();
        
        $content = $sftp->get($remotePath);
        
        if ($content === false) {
            throw new SshDownloadException("Failed to download {$remotePath}");
        }
        
        return $content;
    }
    
    /**
     * Execute command as root using sudo.
     */
    public function sudo(string $command, int $timeout = 30): string
    {
        return $this->exec("sudo {$command}", $timeout);
    }
    
    public function disconnect(): void
    {
        $this->ssh->disconnect();
    }
    
    public function __destruct()
    {
        $this->disconnect();
    }
}
```

### Timeout and Failure Handling

```php
// App\Services\Ssh\SshRetryHandler.php

class SshRetryHandler
{
    /**
     * Wait for SSH to become available with exponential backoff.
     * Used after server creation when SSH isn't immediately ready.
     */
    public function waitForSsh(
        Server $server,
        int $maxAttempts = 20,
        int $initialDelay = 5,
    ): bool {
        $sshService = app(SshService::class);
        $delay = $initialDelay;
        
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            Log::info("SSH attempt {$attempt}/{$maxAttempts} for server {$server->id}");
            
            try {
                if ($sshService->testConnection($server)) {
                    return true;
                }
            } catch (Throwable $e) {
                Log::debug("SSH attempt failed: {$e->getMessage()}");
            }
            
            if ($attempt < $maxAttempts) {
                sleep($delay);
                $delay = min($delay * 1.5, 30); // Max 30 second delay
            }
        }
        
        return false;
    }
}

// Exception handling in jobs
class DeploySiteJob implements ShouldQueue
{
    public function handle(SshService $ssh): void
    {
        try {
            $connection = $ssh->connect($this->deployment->site->server);
            // ... deployment logic
        } catch (SshConnectionException $e) {
            // Connection failed - server may be down
            $this->logOutput("[ERROR] Cannot connect to server: {$e->getMessage()}", 'error');
            throw $e;
        } catch (SshCommandException $e) {
            // Command failed - deployment script error
            $this->logOutput("[ERROR] Command failed (exit {$e->exitCode}): {$e->output}", 'error');
            throw $e;
        } catch (Throwable $e) {
            // Unexpected error
            $this->logOutput("[ERROR] Unexpected error: {$e->getMessage()}", 'error');
            throw $e;
        }
    }
}
```

### Security Considerations for SSH

| Concern | Mitigation |
|---------|------------|
| Private key exposure | Encrypted at rest, never in API responses |
| Man-in-the-middle | Store host fingerprint on first connect, verify on subsequent |
| Key rotation | Support regenerating server keys (future feature) |
| Audit trail | Log all SSH commands executed |
| Least privilege | Use `forge` user, not root, when possible |
| Rate limiting | Limit concurrent SSH connections per server |

---

## 8. Controllers, Services & Actions

### Architecture Overview

We follow a **thin controller, fat service** pattern with Action classes for complex operations:

```
Request → Controller → Action/Service → Repository/Model → Database
                ↓
              Job (if async)
```

### Controllers

| Controller | Responsibility |
|------------|----------------|
| `Auth\*` | Authentication (Laravel Breeze) |
| `DashboardController` | Dashboard stats |
| `ProviderAccountController` | CRUD for provider accounts |
| `ServerController` | Server CRUD + actions |
| `SshKeyController` | SSH key management |
| `SiteController` | Site CRUD |
| `DeploymentController` | Trigger + view deployments |
| `EnvironmentController` | Environment variables |
| `DeployScriptController` | Deploy script editing |
| `WebhookController` | Incoming webhooks |

#### Example: ServerController

```php
// App\Http\Controllers\ServerController.php

class ServerController extends Controller
{
    public function index(): Response
    {
        $servers = auth()->user()
            ->servers()
            ->with('providerAccount:id,name,provider')
            ->withCount('sites')
            ->latest()
            ->paginate(20);
            
        return Inertia::render('Servers/Index', [
            'servers' => ServerResource::collection($servers),
        ]);
    }
    
    public function create(): Response
    {
        $providerAccounts = auth()->user()
            ->providerAccounts()
            ->where('is_valid', true)
            ->get();
            
        return Inertia::render('Servers/Create', [
            'providerAccounts' => ProviderAccountResource::collection($providerAccounts),
            'regions' => $this->getRegionsForProviders($providerAccounts),
            'sizes' => $this->getSizesForProviders($providerAccounts),
        ]);
    }
    
    public function store(
        StoreServerRequest $request,
        CreateServerAction $action,
    ): RedirectResponse {
        $server = $action->execute(
            user: auth()->user(),
            data: ServerData::from($request->validated()),
        );
        
        return redirect()
            ->route('servers.show', $server)
            ->with('success', 'Server is being provisioned...');
    }
    
    public function show(Server $server): Response
    {
        $this->authorize('view', $server);
        
        $server->load([
            'sites' => fn ($q) => $q->withLatestDeployment(),
            'actions' => fn ($q) => $q->latest()->limit(10),
        ]);
        
        return Inertia::render('Servers/Show', [
            'server' => new ServerResource($server),
        ]);
    }
    
    public function destroy(
        Server $server,
        DeleteServerAction $action,
    ): RedirectResponse {
        $this->authorize('delete', $server);
        
        $action->execute($server);
        
        return redirect()
            ->route('servers.index')
            ->with('success', 'Server deletion initiated.');
    }
}
```

### Services

| Service | Responsibility |
|---------|----------------|
| `SshService` | SSH connection management |
| `ProviderManager` | Factory for provider drivers |
| `DigitalOceanProvider` | DigitalOcean API integration |
| `HetznerProvider` | Hetzner API integration |
| `VultrProvider` | Vultr API integration |
| `ProvisioningScriptService` | Generate provisioning scripts |
| `DeploymentService` | Deployment orchestration |
| `NginxConfigService` | Generate Nginx configs |

#### Example: ProviderManager

```php
// App\Services\Providers\ProviderManager.php

class ProviderManager
{
    private array $drivers = [];
    
    public function driver(string $provider): ProviderContract
    {
        return $this->drivers[$provider] ??= $this->createDriver($provider);
    }
    
    public function forAccount(ProviderAccount $account): ProviderContract
    {
        $driver = $this->driver($account->provider);
        $driver->setCredentials($account->credentials);
        
        return $driver;
    }
    
    private function createDriver(string $provider): ProviderContract
    {
        return match ($provider) {
            'digitalocean' => app(DigitalOceanProvider::class),
            'hetzner' => app(HetznerProvider::class),
            'vultr' => app(VultrProvider::class),
            default => throw new InvalidArgumentException("Unknown provider: {$provider}"),
        };
    }
}

// App\Contracts\ProviderContract.php

interface ProviderContract
{
    public function setCredentials(array $credentials): void;
    
    public function validateCredentials(): bool;
    
    public function getRegions(): Collection;
    
    public function getSizes(): Collection;
    
    public function createServer(
        string $name,
        string $size,
        string $region,
        string $sshKeyId,
    ): ProviderServerResult;
    
    public function getServerStatus(string $serverId): ProviderServerStatus;
    
    public function deleteServer(string $serverId): void;
    
    public function createSshKey(string $name, string $publicKey): string;
    
    public function deleteSshKey(string $keyId): void;
}
```

#### Example: DigitalOcean Provider

```php
// App\Services\Providers\DigitalOceanProvider.php

class DigitalOceanProvider implements ProviderContract
{
    private string $apiToken;
    private HttpClient $http;
    
    public function __construct()
    {
        $this->http = Http::baseUrl('https://api.digitalocean.com/v2')
            ->timeout(30)
            ->retry(3, 100);
    }
    
    public function setCredentials(array $credentials): void
    {
        $this->apiToken = $credentials['api_token'];
        $this->http = $this->http->withToken($this->apiToken);
    }
    
    public function createServer(
        string $name,
        string $size,
        string $region,
        string $sshKeyId,
    ): ProviderServerResult {
        $response = $this->http->post('droplets', [
            'name' => $name,
            'region' => $region,
            'size' => $size,
            'image' => 'ubuntu-22-04-x64',
            'ssh_keys' => [$sshKeyId],
            'backups' => false,
            'ipv6' => false,
            'monitoring' => true,
            'tags' => ['serverforge'],
        ]);
        
        if (!$response->successful()) {
            throw new ProviderApiException(
                "DigitalOcean API error: " . $response->body()
            );
        }
        
        $droplet = $response->json('droplet');
        
        return new ProviderServerResult(
            id: (string) $droplet['id'],
            name: $droplet['name'],
            status: 'creating',
        );
    }
    
    public function getServerStatus(string $serverId): ProviderServerStatus
    {
        $response = $this->http->get("droplets/{$serverId}");
        
        if (!$response->successful()) {
            throw new ProviderApiException(
                "Failed to get droplet status: " . $response->body()
            );
        }
        
        $droplet = $response->json('droplet');
        
        // Find public IPv4
        $publicIp = collect($droplet['networks']['v4'] ?? [])
            ->firstWhere('type', 'public')['ip_address'] ?? null;
            
        $privateIp = collect($droplet['networks']['v4'] ?? [])
            ->firstWhere('type', 'private')['ip_address'] ?? null;
        
        return new ProviderServerStatus(
            id: (string) $droplet['id'],
            status: $droplet['status'],
            isActive: $droplet['status'] === 'active',
            ipAddress: $publicIp,
            privateIpAddress: $privateIp,
        );
    }
    
    public function getRegions(): Collection
    {
        $response = $this->http->get('regions');
        
        return collect($response->json('regions'))
            ->filter(fn ($r) => $r['available'])
            ->map(fn ($r) => new ProviderRegion(
                slug: $r['slug'],
                name: $r['name'],
            ));
    }
    
    public function getSizes(): Collection
    {
        $response = $this->http->get('sizes');
        
        return collect($response->json('sizes'))
            ->filter(fn ($s) => $s['available'])
            ->map(fn ($s) => new ProviderSize(
                slug: $s['slug'],
                vcpus: $s['vcpus'],
                memory: $s['memory'],
                disk: $s['disk'],
                priceMonthly: $s['price_monthly'],
            ));
    }
}
```

### Actions

| Action | Purpose |
|--------|---------|
| `CreateServerAction` | Validate, create record, dispatch job |
| `DeleteServerAction` | Soft-delete, dispatch cleanup job |
| `CreateSiteAction` | Create site, dispatch setup job |
| `TriggerDeploymentAction` | Create deployment, dispatch job |
| `SyncSshKeyAction` | Dispatch sync jobs to selected servers |
| `RestartServiceAction` | Dispatch restart job |

#### Example: CreateServerAction

```php
// App\Actions\Servers\CreateServerAction.php

class CreateServerAction
{
    public function __construct(
        private KeyGenerator $keyGenerator,
        private ProviderManager $providers,
    ) {}
    
    public function execute(User $user, ServerData $data): Server
    {
        // Validate provider account belongs to user
        $providerAccount = $user->providerAccounts()
            ->findOrFail($data->providerAccountId);
        
        // Validate provider credentials
        $provider = $this->providers->forAccount($providerAccount);
        if (!$provider->validateCredentials()) {
            throw ValidationException::withMessages([
                'provider_account_id' => 'Provider credentials are invalid.',
            ]);
        }
        
        // Generate SSH keypair for this server
        $keyPair = $this->keyGenerator->generate();
        
        // Create SSH key at provider
        $providerKeyId = $provider->createSshKey(
            name: "serverforge-{$data->name}-" . Str::random(8),
            publicKey: $keyPair->publicKey,
        );
        
        // Create server record
        $server = DB::transaction(function () use (
            $user,
            $data,
            $providerAccount,
            $keyPair,
            $providerKeyId,
        ) {
            $server = $user->servers()->create([
                'provider_account_id' => $providerAccount->id,
                'provider' => $providerAccount->provider,
                'name' => $data->name,
                'size' => $data->size,
                'region' => $data->region,
                'php_version' => $data->phpVersion,
                'database_type' => $data->databaseType,
                'status' => ServerStatus::PENDING,
                'meta' => [
                    'provider_ssh_key_id' => $providerKeyId,
                ],
            ]);
            
            // Store credentials
            $server->credentials()->createMany([
                [
                    'type' => 'private_key',
                    'value' => $keyPair->privateKey,
                ],
                [
                    'type' => 'sudo_password',
                    'value' => Str::random(32),
                ],
                [
                    'type' => 'database_password',
                    'value' => Str::random(32),
                ],
            ]);
            
            return $server;
        });
        
        // Dispatch provisioning job
        ProvisionServerJob::dispatch($server);
        
        return $server;
    }
}
```

### DTOs (Data Transfer Objects)

```php
// App\Data\ServerData.php

use Spatie\LaravelData\Data;

class ServerData extends Data
{
    public function __construct(
        public string $name,
        public int $providerAccountId,
        public string $region,
        public string $size,
        public string $phpVersion = '8.3',
        public string $databaseType = 'mysql',
    ) {}
    
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9-]+$/'],
            'providerAccountId' => ['required', 'integer', 'exists:provider_accounts,id'],
            'region' => ['required', 'string', 'max:50'],
            'size' => ['required', 'string', 'max:50'],
            'phpVersion' => ['sometimes', 'string', Rule::in(['8.1', '8.2', '8.3'])],
            'databaseType' => ['sometimes', 'string', Rule::in(['mysql', 'postgresql', 'mariadb'])],
        ];
    }
}
```

---

## 9. API Endpoints

### Authentication Endpoints (Laravel Breeze)

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/login` | Login page |
| POST | `/login` | Authenticate |
| POST | `/logout` | Logout |
| GET | `/register` | Registration page |
| POST | `/register` | Create account |
| GET | `/forgot-password` | Password reset request |
| POST | `/forgot-password` | Send reset email |
| GET | `/reset-password/{token}` | Reset password form |
| POST | `/reset-password` | Reset password |

### Dashboard

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/dashboard` | Dashboard with stats |

### Provider Accounts

| Method | URL | Description | Payload |
|--------|-----|-------------|---------|
| GET | `/provider-accounts` | List provider accounts | - |
| GET | `/provider-accounts/create` | Create form | - |
| POST | `/provider-accounts` | Create provider account | `{ provider, name, credentials: { api_token } }` |
| GET | `/provider-accounts/{id}` | View account details | - |
| PUT | `/provider-accounts/{id}` | Update account | `{ name, credentials }` |
| DELETE | `/provider-accounts/{id}` | Delete account | - |
| POST | `/provider-accounts/{id}/validate` | Validate credentials | - |

### Servers

| Method | URL | Description | Payload |
|--------|-----|-------------|---------|
| GET | `/servers` | List servers | - |
| GET | `/servers/create` | Create form | - |
| POST | `/servers` | Create server | `{ name, provider_account_id, region, size, php_version, database_type }` |
| GET | `/servers/{ulid}` | Server detail | - |
| DELETE | `/servers/{ulid}` | Delete server | - |
| POST | `/servers/{ulid}/restart/{service}` | Restart service | `service: nginx|php|mysql|redis` |
| POST | `/servers/{ulid}/reboot` | Reboot server | - |
| GET | `/servers/{ulid}/actions` | List actions | - |

### SSH Keys

| Method | URL | Description | Payload |
|--------|-----|-------------|---------|
| GET | `/ssh-keys` | List SSH keys | - |
| POST | `/ssh-keys` | Add SSH key | `{ name, public_key }` |
| DELETE | `/ssh-keys/{ulid}` | Delete SSH key | - |
| POST | `/ssh-keys/{ulid}/sync` | Sync to servers | `{ server_ids: [] }` |
| DELETE | `/ssh-keys/{ulid}/servers/{server}` | Revoke from server | - |

### Sites

| Method | URL | Description | Payload |
|--------|-----|-------------|---------|
| GET | `/servers/{server}/sites` | List sites on server | - |
| GET | `/servers/{server}/sites/create` | Create form | - |
| POST | `/servers/{server}/sites` | Create site | `{ domain, repository, branch, project_type, directory }` |
| GET | `/sites/{ulid}` | Site detail | - |
| PUT | `/sites/{ulid}` | Update site | `{ branch, directory, auto_deploy }` |
| DELETE | `/sites/{ulid}` | Delete site | - |

### Deployments

| Method | URL | Description | Payload |
|--------|-----|-------------|---------|
| GET | `/sites/{site}/deployments` | List deployments | - |
| POST | `/sites/{site}/deployments` | Trigger deployment | - |
| GET | `/deployments/{ulid}` | Deployment detail + logs | - |
| POST | `/deployments/{ulid}/cancel` | Cancel deployment | - |

### Environment Variables

| Method | URL | Description | Payload |
|--------|-----|-------------|---------|
| GET | `/sites/{site}/environment` | Get env vars | - |
| PUT | `/sites/{site}/environment` | Update env vars | `{ variables: { KEY: value } }` |

### Deploy Scripts

| Method | URL | Description | Payload |
|--------|-----|-------------|---------|
| GET | `/sites/{site}/deploy-script` | Get script | - |
| PUT | `/sites/{site}/deploy-script` | Update script | `{ script: "..." }` |

### Webhooks (External)

| Method | URL | Description |
|--------|-----|-------------|
| POST | `/webhook/github/{site}` | GitHub push webhook |
| POST | `/webhook/gitlab/{site}` | GitLab push webhook |
| POST | `/api/deploy/{site}` | API deployment trigger (with token) |

### API Response Examples

#### Server Resource

```json
{
  "data": {
    "id": "01HQXYZ...",
    "name": "production-1",
    "provider": "digitalocean",
    "provider_account": {
      "id": 1,
      "name": "My DO Account"
    },
    "region": "nyc1",
    "size": "s-2vcpu-4gb",
    "ip_address": "192.168.1.100",
    "status": "active",
    "php_version": "8.3",
    "database_type": "mysql",
    "sites_count": 3,
    "provisioned_at": "2026-01-10T10:00:00Z",
    "created_at": "2026-01-10T09:45:00Z"
  }
}
```

#### Deployment Resource

```json
{
  "data": {
    "id": "01HQABC...",
    "site": {
      "id": "01HQDEF...",
      "domain": "myapp.com"
    },
    "status": "finished",
    "commit_hash": "a1b2c3d4e5f6...",
    "commit_message": "Fix authentication bug",
    "commit_author": "John Doe",
    "triggered_by": "manual",
    "duration_seconds": 45,
    "started_at": "2026-01-14T10:00:00Z",
    "finished_at": "2026-01-14T10:00:45Z"
  }
}
```

---

*Continued in TECHNICAL_PLAN_PART4.md*
