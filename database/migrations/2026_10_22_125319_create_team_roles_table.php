<?php

use App\Enums\TeamRoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the roles table with the four built-in roles, then point every
     * membership and pending invitation at a role row instead of the old
     * free-text `role` column: "owner" becomes Owner and anything else
     * ("member") becomes Developer, so nobody loses access.
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

        $roleIds = DB::table('team_roles')->whereNull('team_id')->pluck('id', 'key');

        foreach (['team_user', 'team_invitations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('team_role_id')->nullable()->after('role')->constrained('team_roles')->restrictOnDelete();
            });

            DB::table($tableName)->where('role', 'owner')->update(['team_role_id' => $roleIds[TeamRoleKey::Owner->value]]);
            DB::table($tableName)->where('role', '!=', 'owner')->update(['team_role_id' => $roleIds[TeamRoleKey::Developer->value]]);

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('team_role_id')->nullable(false)->change();
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        $ownerRoleId = DB::table('team_roles')
            ->whereNull('team_id')
            ->where('key', TeamRoleKey::Owner->value)
            ->value('id');

        foreach (['team_user', 'team_invitations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('role')->default('member')->after('team_role_id');
            });

            DB::table($tableName)->where('team_role_id', $ownerRoleId)->update(['role' => 'owner']);

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('team_role_id');
            });
        }

        Schema::dropIfExists('team_roles');
    }
};
