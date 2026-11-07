<?php

namespace App\Actions\Backups;

use App\Models\Backup;
use App\Models\ServerDatabase;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\Storage\StorageProviderManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Loads a dump made somewhere else (another Flitops install, another host)
 * into a server database. The uploaded file is kept in the team's storage
 * bucket as a backup marked "import", then loaded with the same pipeline a
 * restore uses, so the server pulls it straight from the bucket.
 */
class ImportDatabaseDump
{
    public function __construct(
        private StorageProviderManager $storageProviderManager,
        private RestoreDatabaseBackup $restoreDatabaseBackup,
    ) {}

    public function execute(
        ServerDatabase $serverDatabase,
        StorageProvider $storageProvider,
        UploadedFile $dump,
        User $user,
    ): Backup {
        $path = $this->buildStoragePath($serverDatabase, $dump);
        $stream = fopen($dump->getRealPath(), 'rb');

        try {
            $this->storageProviderManager->forAccount($storageProvider)->putStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $backup = $serverDatabase->backups()->create([
            'storage_provider_id' => $storageProvider->id,
            'user_id' => $user->id,
            'status' => 'completed',
            'triggered_by' => 'import',
            'storage_path' => $path,
            'size_bytes' => $dump->getSize(),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->restoreDatabaseBackup->trigger($backup);

        return $backup;
    }

    private function buildStoragePath(ServerDatabase $serverDatabase, UploadedFile $dump): string
    {
        $timestamp = now()->format('Ymd_His');
        $extension = str_ends_with(strtolower($dump->getClientOriginalName()), '.gz') ? 'sql.gz' : 'sql';
        $random = Str::lower(Str::random(8));

        return "imports/{$serverDatabase->id}/{$timestamp}_{$random}_{$serverDatabase->name}.{$extension}";
    }
}
