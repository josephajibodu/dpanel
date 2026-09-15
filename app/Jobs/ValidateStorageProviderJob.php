<?php

namespace App\Jobs;

use App\Models\StorageProvider;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ValidateStorageProviderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public StorageProvider $storageProvider
    ) {}

    /**
     * Execute the job.
     */
    public function handle(StorageProviderManager $storageProviderManager): void
    {
        $driver = $storageProviderManager->forAccount($this->storageProvider);

        $isValid = $driver->validateCredentials();

        $this->storageProvider->update([
            'is_valid' => $isValid,
            'validated_at' => $isValid ? now() : $this->storageProvider->validated_at,
        ]);
    }
}
