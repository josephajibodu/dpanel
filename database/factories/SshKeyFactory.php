<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SshKey>
 */
class SshKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true).' Key',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.fake()->regexify('[A-Za-z0-9]{43}').' '.fake()->email(),
            'fingerprint' => 'SHA256:'.fake()->regexify('[A-Za-z0-9+/]{43}'),
        ];
    }
}
