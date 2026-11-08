<?php

use App\Enums\TeamRoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the roles table along with the built-in roles every team uses.
     */
    public function up(): void
    {
        Schema::create('team_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->timestamps();

            $table->unique(['team_id', 'key']);
        });

        foreach (TeamRoleKey::cases() as $roleKey) {
            DB::table('team_roles')->insert([
                'team_id' => null,
                'key' => $roleKey->value,
                'name' => $roleKey->label(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_roles');
    }
};
