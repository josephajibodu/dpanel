<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceControlRepository extends Model
{
    /** @use HasFactory<\Database\Factories\SourceControlRepositoryFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'source_control_account_id',
        'provider_repo_id',
        'name',
        'full_name',
        'ssh_url',
        'html_url',
        'default_branch',
        'private',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private' => 'boolean',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function sourceControlAccount(): BelongsTo
    {
        return $this->belongsTo(SourceControlAccount::class);
    }
}
