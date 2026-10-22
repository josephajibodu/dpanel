<?php

namespace App\Contracts;

interface StorageProviderContract
{
    /**
     * Set the credentials for this storage account.
     *
     * @param  array<string, string>  $credentials
     */
    public function setCredentials(array $credentials): void;

    /**
     * Validate the credentials by writing and then deleting a small marker
     * object — proves both authentication and the write permission backups
     * actually need, not just read access.
     */
    public function validateCredentials(): bool;

    /**
     * Upload a stream to the given path in the connected bucket.
     *
     * @param  resource  $stream
     */
    public function putStream(string $path, $stream): void;

    /**
     * Delete an object from the connected bucket.
     */
    public function delete(string $path): void;

    /**
     * Get a temporary, signed URL to download an object directly from the
     * bucket without it passing through the Flitops app server.
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expiry): string;

    /**
     * Get the size in bytes of an object, or null if it doesn't exist.
     */
    public function size(string $path): ?int;

    /**
     * Build the contents of an AWS CLI credentials file the AWS CLI on a
     * *managed server* (not this app) can authenticate against this account
     * with. It's uploaded as a file rather than passed in the command, so the
     * keys never show up in the server's process list.
     */
    public function awsCredentialsFile(): string;

    /**
     * Build the shell-escaped extra `aws` CLI flags a managed-server command
     * needs for this account, e.g. `--endpoint-url='...' --region='...'`.
     */
    public function awsCliArgs(): string;

    /**
     * The bucket name backups/objects should be written to.
     */
    public function bucket(): string;
}
