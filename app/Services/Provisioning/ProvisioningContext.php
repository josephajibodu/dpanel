<?php

namespace App\Services\Provisioning;

use App\Contracts\Provisioning\PackageManager;
use App\Contracts\Provisioning\ServiceManager;
use App\Contracts\Remote\RemoteCommandRunner;
use App\Contracts\Remote\RemoteFilesystem;
use App\Models\Server;

class ProvisioningContext
{
    public function __construct(
        public Server $server,
        public RemoteCommandRunner $runner,
        public RemoteFilesystem $files,
        public PackageManager $packages,
        public ServiceManager $services,
        public string $serverUser,
        public string $sudoPassword,
        public string $databasePassword,
        public ?ServiceInstallationRunner $installationRunner = null,
    ) {}
}
