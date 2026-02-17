<?php

namespace App\Services\Provisioning;

class RedisService
{
    public function install(ProvisioningContext $context): void
    {
        $context->packages->ensureInstalled('redis-server');

        $context->services->enable('redis-server');
        $context->services->start('redis-server');
    }

    public function restart(ProvisioningContext $context): void
    {
        $context->services->restart('redis-server');
    }

    public function stop(ProvisioningContext $context): void
    {
        $context->services->stop('redis-server');
    }
}
