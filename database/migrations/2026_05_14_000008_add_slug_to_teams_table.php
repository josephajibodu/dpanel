<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        // Backfill slugs for existing teams
        Team::query()->whereNull('slug')->orderBy('id')->each(function (Team $team) {
            $base = Str::slug($team->name);
            $slug = $base;
            $i = 2;

            while (DB::table('teams')->where('slug', $slug)->exists()) {
                $slug = "{$base}-{$i}";
                $i++;
            }

            DB::table('teams')->where('id', $team->id)->update(['slug' => $slug]);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
