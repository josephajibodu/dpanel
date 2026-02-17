<?php

namespace App\Contracts\Remote;

interface RemoteFilesystem
{
    /**
     * Write the given contents to the given path on the remote server.
     */
    public function put(string $path, string $contents): void;

    /**
     * Read the contents of the given path from the remote server.
     */
    public function get(string $path): string;

    /**
     * Determine if a file exists on the remote server.
     */
    public function exists(string $path): bool;

    /**
     * Determine if a directory exists on the remote server.
     */
    public function isDirectory(string $path): bool;

    /**
     * Ensure that the given directory exists on the remote server.
     */
    public function ensureDirectory(string $path): void;

    /**
     * Remove a file on the remote server if it exists.
     */
    public function delete(string $path): void;

    /**
     * Create or replace a symlink on the remote server.
     */
    public function symlink(string $target, string $link): void;

    /**
     * Backup a file on the remote server if it exists.
     *
     * Implementations are free to choose the backup strategy (for example,
     * copying to \"*.bak\" or timestamped filenames).
     */
    public function backup(string $path): void;
}
