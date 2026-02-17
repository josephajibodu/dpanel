<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Worker>
 */
class WorkerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'site_id' => null,
            'command' => 'php /var/www/html/artisan queue:work',
            'user' => config('server.user', 'deploy'),
            'auto_start' => true,
            'auto_restart' => true,
            'numprocs' => 1,
            'redirect_stderr' => true,
            'stdout_logfile' => '/var/log/worker.log',
            'status' => 'active',
            'name' => 'worker-'.fake()->randomNumber(3),
        ];
    }

    public function forSite(Site $site): static
    {
        return $this->state(fn (array $attributes) => [
            'server_id' => $site->server_id,
            'site_id' => $site->id,
        ]);
    }
}
