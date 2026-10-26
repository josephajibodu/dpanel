<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Builds and runs the Go terminal gateway for local development, with its
 * settings taken from the app's own config. `php artisan dev` starts it
 * alongside Horizon and Reverb. Production runs the built binary under
 * supervisord instead.
 */
class RunTerminalGatewayCommand extends Command
{
    protected $signature = 'flitops:terminal-gateway
        {--listen=127.0.0.1:8090 : The address the gateway listens on}';

    protected $description = 'Build and run the terminal gateway for local development.';

    public function handle(): int
    {
        $secret = config('services.terminal_gateway.secret');

        if (blank($secret) || blank(config('services.terminal_gateway.url'))) {
            $this->components->info('The terminal is not configured (TERMINAL_GATEWAY_URL / TERMINAL_GATEWAY_SECRET), so the gateway was not started.');

            return self::SUCCESS;
        }

        if (! Process::run('command -v go')->successful()) {
            $this->components->warn('Go is not installed, so the terminal gateway was not started.');

            return self::SUCCESS;
        }

        $this->runGateway([
            'LISTEN_ADDR' => (string) $this->option('listen'),
            'FLITOPS_URL' => (string) config('app.url'),
            'ALLOWED_ORIGINS' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            'GATEWAY_SECRET' => (string) $secret,
        ]);

        return self::FAILURE;
    }

    /**
     * Replace this PHP process with the gateway, so stopping `php artisan dev`
     * stops the gateway too instead of leaving it holding the port.
     *
     * @param  array<string, string>  $settings
     */
    protected function runGateway(array $settings): void
    {
        $script = 'cd '.escapeshellarg(base_path('terminal-gateway'))
            .' && go build -o flitops-terminal . && exec ./flitops-terminal';

        pcntl_exec('/bin/sh', ['-c', $script], [...getenv(), ...$settings]);

        $this->components->error('Could not start the terminal gateway.');
    }
}
