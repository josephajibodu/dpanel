<?php

namespace App\Enums;

enum ProvisioningStep: int
{
    case Pending = 0;
    case WaitingForServer = 1;
    case PreparingServer = 2;
    case ConfiguringSwap = 3;
    case InstallingBaseDependencies = 4;
    case InstallingPhp = 5;
    case InstallingNginx = 6;
    case InstallingDatabase = 7;
    case InstallingRedis = 8;
    case MakingFinalTouches = 9;
    case Finished = 10;

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::WaitingForServer => 'Waiting on your server to become ready',
            self::PreparingServer => 'Preparing your server',
            self::ConfiguringSwap => 'Configuring swap',
            self::InstallingBaseDependencies => 'Installing base dependencies',
            self::InstallingPhp => 'Installing PHP',
            self::InstallingNginx => 'Installing Nginx',
            self::InstallingDatabase => 'Installing database',
            self::InstallingRedis => 'Installing Redis',
            self::MakingFinalTouches => 'Making final touches',
            self::Finished => 'Provisioning complete',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pending => 'Waiting to start provisioning.',
            self::WaitingForServer => 'We are waiting to hear from your server to confirm the provisioning process has started.',
            self::PreparingServer => 'Creating the server user and configuring SSH access.',
            self::ConfiguringSwap => 'Setting up swap space for better memory management.',
            self::InstallingBaseDependencies => 'Installing the basic dependencies required to provision your server. It will be updated to the latest Ubuntu patch version.',
            self::InstallingPhp => 'PHP will be installed along with common extensions required for Laravel applications.',
            self::InstallingNginx => 'Nginx will be installed. We will also configure gzip, PHP-FPM and other PHP related Nginx settings.',
            self::InstallingDatabase => 'Installing and configuring your selected database server.',
            self::InstallingRedis => 'Redis will be installed for caching and queues.',
            self::MakingFinalTouches => 'Installing Composer, Node.js, Supervisor, and configuring the firewall.',
            self::Finished => 'Your server has been provisioned and is ready to use.',
        };
    }

    /**
     * Check if this step is completed (another step is in progress).
     */
    public function isCompleted(self $currentStep): bool
    {
        return $this->value < $currentStep->value;
    }

    /**
     * Check if this step is the current step.
     */
    public function isCurrent(self $currentStep): bool
    {
        return $this->value === $currentStep->value;
    }

    /**
     * Check if this step is pending (not yet started).
     */
    public function isPending(self $currentStep): bool
    {
        return $this->value > $currentStep->value;
    }

    /**
     * Get all steps that should be displayed in the UI.
     *
     * @return array<self>
     */
    public static function displayableSteps(): array
    {
        return [
            self::WaitingForServer,
            self::PreparingServer,
            self::ConfiguringSwap,
            self::InstallingBaseDependencies,
            self::InstallingPhp,
            self::InstallingNginx,
            self::InstallingDatabase,
            self::InstallingRedis,
            self::MakingFinalTouches,
        ];
    }
}
