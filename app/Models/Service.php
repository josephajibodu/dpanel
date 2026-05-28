<?php

namespace App\Models;

use App\Contracts\Provisioning\ServiceManager;
use App\Enums\ProvisioningStep;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Services\Provisioning\ProvisioningContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceFactory> */
    use HasFactory;

    protected $table = 'server_services';

    protected $fillable = [
        'server_id',
        'type',
        'type_data',
        'name',
        'version',
        'installed_version',
        'unit',
        'logs',
        'status',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'type_data' => 'array',
            'is_default' => 'boolean',
            'status' => ServiceStatus::class,
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Run the installer for this service using the provisioning context, then update status and unit.
     */
    public function install(ProvisioningContext $context): void
    {
        if ($context->installationRunner === null) {
            throw new \RuntimeException('ProvisioningContext has no installation runner; cannot install service.');
        }

        $this->update(['status' => ServiceStatus::Installing]);
        $this->refresh();

        $step = ProvisioningStep::forServiceType($this->type);
        if ($step !== null) {
            $this->server->update(['provisioning_step' => $step]);
        }

        try {
            $result = $context->installationRunner->run($this->type, $context);

            $this->update([
                'unit' => $result['unit'],
                'installed_version' => $result['installed_version'],
                'status' => ServiceStatus::Active,
            ]);
        } catch (\Throwable $e) {
            $this->update(['status' => ServiceStatus::Failed]);
            throw $e;
        }
    }

    public function start(ServiceManager $manager): void
    {
        $this->ensureUnit();
        $manager->start($this->unit);
    }

    public function stop(ServiceManager $manager): void
    {
        $this->ensureUnit();
        $manager->stop($this->unit);
    }

    public function enable(ServiceManager $manager): void
    {
        $this->ensureUnit();
        $manager->enable($this->unit);
    }

    public function restart(ServiceManager $manager): void
    {
        $this->ensureUnit();
        $manager->restart($this->unit);
    }

    public function reload(ServiceManager $manager): void
    {
        $this->ensureUnit();
        $manager->reload($this->unit);
    }

    public function disable(ServiceManager $manager): void
    {
        $this->ensureUnit();
        $manager->disable($this->unit);
    }

    private function ensureUnit(): void
    {
        if (empty($this->unit)) {
            throw new \RuntimeException("Service [{$this->type}] (id: {$this->id}) has no systemd unit configured.");
        }
    }
}
