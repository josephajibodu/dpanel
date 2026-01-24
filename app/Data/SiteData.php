<?php

namespace App\Data;

use App\Enums\ProjectType;
use App\Enums\RepositoryProvider;

readonly class SiteData
{
    /**
     * @param  array<string>|null  $aliases
     */
    public function __construct(
        public int $serverId,
        public ?string $domain = null,
        public ?string $siteName = null,
        public ?array $aliases = null,
        public string $directory = '/public',
        public ?string $repository = null,
        public ?int $sourceControlAccountId = null,
        public RepositoryProvider $repositoryProvider = RepositoryProvider::Github,
        public string $branch = 'main',
        public ProjectType $projectType = ProjectType::Laravel,
        public string $phpVersion = '8.3',
        public ?string $packageManager = null,
        public ?string $buildCommand = null,
        public bool $autoDeploy = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        return new self(
            serverId: $data['server_id'],
            domain: $data['domain'] ?? null,
            siteName: $data['site_name'] ?? null,
            aliases: $data['aliases'] ?? null,
            directory: $data['directory'] ?? '/public',
            repository: $data['repository'] ?? null,
            sourceControlAccountId: $data['source_control_account_id'] ?? null,
            repositoryProvider: isset($data['repository_provider'])
                ? RepositoryProvider::from($data['repository_provider'])
                : RepositoryProvider::Github,
            branch: $data['branch'] ?? 'main',
            projectType: isset($data['project_type'])
                ? ProjectType::from($data['project_type'])
                : ProjectType::Laravel,
            phpVersion: $data['php_version'] ?? '8.3',
            packageManager: $data['package_manager'] ?? null,
            buildCommand: $data['build_command'] ?? null,
            autoDeploy: $data['auto_deploy'] ?? false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'server_id' => $this->serverId,
            'domain' => $this->domain,
            'site_name' => $this->siteName,
            'aliases' => $this->aliases,
            'directory' => $this->directory,
            'repository' => $this->repository,
            'source_control_account_id' => $this->sourceControlAccountId,
            'repository_provider' => $this->repositoryProvider->value,
            'branch' => $this->branch,
            'project_type' => $this->projectType->value,
            'php_version' => $this->phpVersion,
            'package_manager' => $this->packageManager,
            'build_command' => $this->buildCommand,
            'auto_deploy' => $this->autoDeploy,
        ];
    }
}
