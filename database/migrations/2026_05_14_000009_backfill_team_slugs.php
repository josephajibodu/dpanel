<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Team::query()->orderBy('id')->each(function (Team $team) {
            $base = Str::slug($team->name);
            $slug = $base;
            $i = 2;

            while (Team::where('slug', $slug)->where('id', '!=', $team->id)->exists()) {
                $slug = "{$base}-{$i}";
                $i++;
            }

            $team->updateQuietly(['slug' => $slug]);
        });
    }

    public function down(): void
    {
        // Slugs can be dropped via the previous migration's down()
    }
};
