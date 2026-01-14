<?php

namespace App\Contracts;

use App\Data\ProviderRegion;
use App\Data\ProviderServerResult;
use App\Data\ProviderServerStatus;
use App\Data\ProviderSize;
use Illuminate\Support\Collection;

interface ProviderContract
{
    /**
     * Set the API credentials for this provider.
     *
     * @param  array{api_token: string}  $credentials
     */
    public function setCredentials(array $credentials): void;

    /**
     * Validate that the credentials are valid by making an API call.
     */
    public function validateCredentials(): bool;

    /**
     * Get available regions for this provider.
     *
     * @return Collection<int, ProviderRegion>
     */
    public function getRegions(): Collection;

    /**
     * Get available server sizes for this provider.
     *
     * @return Collection<int, ProviderSize>
     */
    public function getSizes(): Collection;

    /**
     * Create a new server at the provider.
     */
    public function createServer(
        string $name,
        string $size,
        string $region,
        string $sshKeyId,
    ): ProviderServerResult;

    /**
     * Get the current status of a server.
     */
    public function getServerStatus(string $serverId): ProviderServerStatus;

    /**
     * Delete a server from the provider.
     */
    public function deleteServer(string $serverId): void;

    /**
     * Create an SSH key at the provider.
     *
     * @return string The provider's SSH key ID
     */
    public function createSshKey(string $name, string $publicKey): string;

    /**
     * Delete an SSH key from the provider.
     */
    public function deleteSshKey(string $keyId): void;
}
