<?php

use App\Contracts\Remote\RemoteCommandRunner;
use App\Services\Provisioning\AptPackageManager;

it('installs packages without asking interactive questions', function () {
    $runner = Mockery::mock(RemoteCommandRunner::class);
    $runner->shouldReceive('runQuietly')
        ->once()
        ->with('DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=60 install -y nginx', 900);

    (new AptPackageManager($runner))->installOrFail('nginx');
});
