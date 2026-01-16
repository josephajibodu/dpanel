<?php

namespace App\Models;

use App\Enums\Provider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderSize extends Model
{
    /** @use HasFactory<\Database\Factories\ProviderSizeFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
        'code',
        'name',
        'label',
        'memory',
        'disk',
        'cpus',
        'price_monthly',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'cpus' => 'integer',
            'price_monthly' => 'decimal:2',
        ];
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * Get a human-readable description of the size.
     */
    public function description(): string
    {
        return sprintf(
            '%s RAM · %d vCPU%s · %s SSD',
            $this->memory,
            $this->cpus,
            $this->cpus > 1 ? 's' : '',
            $this->disk
        );
    }
}
