<?php

namespace App\Actions\Backups;

use App\Exceptions\BackupFailedException;
use App\Models\Backup;
use App\Services\Storage\StorageProviderManager;

/**
 * Checks the backup's storage key from Flitops itself before any work starts
 * on the server, so an expired or revoked key fails with a plain message
 * instead of surfacing as a failed command on the server.
 */
class EnsureBackupStorageIsReachable
{
    public function __construct(
        private StorageProviderManager $storageProviderManager,
    ) {}

    /**
     * @param  'backup'|'restore'  $operation
     *
     * @throws BackupFailedException
     */
    public function execute(Backup $backup, string $operation = 'backup'): void
    {
        $storageProvider = $backup->storageProvider;

        if (! $storageProvider) {
            throw new BackupFailedException("This backup's storage provider has been deleted, so the {$operation} couldn't start.");
        }

        $isValid = $this->storageProviderManager->forAccount($storageProvider)->validateCredentials();

        $storageProvider->update([
            'is_valid' => $isValid,
            'validated_at' => $isValid ? now() : $storageProvider->validated_at,
        ]);

        if (! $isValid) {
            throw new BackupFailedException(sprintf(
                '%s didn\'t accept the access key on the "%s" storage provider. The key has most likely expired or been revoked (or the bucket was deleted). Update it under Storage providers, then run the %s again.',
                $storageProvider->type->label(),
                $storageProvider->name,
                $operation,
            ));
        }
    }
}
