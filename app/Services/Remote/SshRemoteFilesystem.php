<?php

namespace App\Services\Remote;

use App\Contracts\Remote\RemoteFilesystem;
use App\Services\Ssh\SshConnection;

class SshRemoteFilesystem implements RemoteFilesystem
{
    public function __construct(
        private SshConnection $connection,
    ) {}

    public function put(string $path, string $contents): void
    {
        $this->connection->upload($contents, $path);
    }

    public function get(string $path): string
    {
        return $this->connection->download($path);
    }

    public function exists(string $path): bool
    {
        return $this->connection->fileExists($path);
    }

    public function isDirectory(string $path): bool
    {
        return $this->connection->directoryExists($path);
    }

    public function ensureDirectory(string $path): void
    {
        $this->connection->exec('mkdir -p '.escapeshellarg($path));
    }

    public function delete(string $path): void
    {
        if ($this->exists($path)) {
            $this->connection->exec('rm -f '.escapeshellarg($path));
        }
    }

    public function symlink(string $target, string $link): void
    {
        $this->connection->exec('ln -snf '.escapeshellarg($target).' '.escapeshellarg($link));
    }

    public function backup(string $path): void
    {
        if (! $this->exists($path)) {
            return;
        }

        $timestamp = date('YmdHis');
        $backupPath = "{$path}.bak.{$timestamp}";

        $this->connection->exec('cp '.escapeshellarg($path).' '.escapeshellarg($backupPath));
    }
}
