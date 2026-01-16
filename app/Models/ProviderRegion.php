<?php

namespace App\Models;

use App\Enums\Provider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderRegion extends Model
{
    /** @use HasFactory<\Database\Factories\ProviderRegionFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
        'code',
        'name',
        'alternate_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
        ];
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
