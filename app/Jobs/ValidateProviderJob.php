<?php

namespace App\Jobs;

use App\Models\ProviderAccount;
use App\Services\Providers\ProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ValidateProviderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ProviderAccount $providerAccount
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ProviderManager $providerManager): void
    {
        $provider = $providerManager->forAccount($this->providerAccount);

        $isValid = $provider->validateCredentials();

        $this->providerAccount->update([
            'is_valid' => $isValid,
            'validated_at' => $isValid ? now() : $this->providerAccount->validated_at,
        ]);
    }
}
