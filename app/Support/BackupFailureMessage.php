<?php

namespace App\Support;

use App\Exceptions\BackupFailedException;
use App\Exceptions\SshCommandException;
use App\Exceptions\SshConnectionException;
use App\Exceptions\SshHostKeyMismatchException;
use App\Models\Backup;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * Turns a backup or restore failure into a message that is safe to show the
 * user. Causes they can fix themselves are named specifically; anything else
 * points at Flitops itself, so it gets a generic message and the real
 * exception is left to the job's log entry.
 */
class BackupFailureMessage
{
    /**
     * @param  'backup'|'restore'  $operation
     */
    public static function for(Throwable $exception, Backup $backup, string $operation = 'backup'): string
    {
        $message = match (true) {
            $exception instanceof BackupFailedException => $exception->getMessage(),
            $exception instanceof SshHostKeyMismatchException => $exception->getMessage(),
            $exception instanceof SshConnectionException => "Couldn't connect to the server over SSH. Make sure it's online and reachable, then try again.",
            $exception instanceof TimeoutExceededException => "The {$operation} didn't finish within an hour and was stopped.",
            $exception instanceof SshCommandException => self::forRemoteOutput($exception->stderr.$exception->output, $backup, $operation),
            default => null,
        };

        return $message ?? sprintf(
            "Something went wrong on our end while %s. We've logged the details — try again, and contact support if it keeps happening.",
            $operation === 'restore' ? 'restoring this backup' : 'running this backup',
        );
    }

    /**
     * Match what the remote tools (aws, mysqldump, pg_dump, the SQLite
     * scripts) printed against the failures the user can act on.
     */
    private static function forRemoteOutput(string $output, Backup $backup, string $operation): ?string
    {
        $provider = $backup->storageProvider;
        $storage = $provider?->type->label() ?? 'The storage provider';

        return match (true) {
            (bool) preg_match('/An error occurred \((Unauthorized|AccessDenied|InvalidAccessKeyId|SignatureDoesNotMatch|Forbidden|403)\)/', $output) => "{$storage} didn't accept the access key on the \"{$provider?->name}\" storage provider. The key has most likely expired or been revoked, or lacks read and write access to the bucket. Update it under Storage providers, then run the {$operation} again.",
            str_contains($output, 'An error occurred (NoSuchBucket)') => "The bucket set on the \"{$provider?->name}\" storage provider doesn't exist.",
            str_contains($output, 'Could not connect to the endpoint URL') => "The server couldn't reach {$storage}. Check that it can make outbound HTTPS connections.",
            str_contains($output, 'No space left on device') => "The server ran out of disk space during the {$operation}.",
            str_contains($output, 'SQLite database not found at') => "No SQLite database file was found at {$backup->site?->sqliteDatabasePath()} on the server.",
            str_contains($output, 'SQLite integrity check failed') => 'The backup was uploaded but failed its integrity check, so it was discarded. Try running it again.',
            (bool) preg_match('/Unknown database|database ".+" does not exist/', $output) => "The database \"{$backup->targetName()}\" no longer exists on the server.",
            default => null,
        };
    }
}
