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
     * Build the shell-escaped `KEY=value` environment prefix an SSH command
     * needs so the AWS CLI running on a *managed server* (not this app) can
     * authenticate against this account, e.g.
     * `AWS_ACCESS_KEY_ID='...' AWS_SECRET_ACCESS_KEY='...'`.
     */
    public function envPrefix(): string;

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
