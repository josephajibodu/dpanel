<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->orderBy('id')->each(function (object $user) {
            $teamId = DB::table('teams')->insertGetId([
                'user_id' => $user->id,
                'name' => $user->name."'s Team",
                'personal_team' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('team_user')->insert([
                'team_id' => $teamId,
                'user_id' => $user->id,
                'role' => 'owner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('users')->where('id', $user->id)->update([
                'current_team_id' => $teamId,
            ]);

            DB::table('servers')->where('user_id', $user->id)->update([
                'team_id' => $teamId,
            ]);

            DB::table('provider_accounts')->where('user_id', $user->id)->update([
                'team_id' => $teamId,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('servers')->update(['team_id' => null]);
        DB::table('provider_accounts')->update(['team_id' => null]);
        DB::table('users')->update(['current_team_id' => null]);
        DB::table('team_user')->delete();
        DB::table('teams')->delete();
    }
};
