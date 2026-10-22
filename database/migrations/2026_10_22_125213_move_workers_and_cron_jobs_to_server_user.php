<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Workers and cron jobs may now only run as the server user. Move any
     * saved before that rule (as root, www-data, ...) onto it so they can
     * be saved and written to the server again.
     */
    public function up(): void
    {
        $serverUser = config('server.user');

        foreach (['workers', 'cron_jobs'] as $table) {
            DB::table($table)
                ->where(fn (Builder $query) => $query->whereNull('user')->orWhere('user', '!=', $serverUser))
                ->update(['user' => $serverUser]);
        }
    }

    /**
     * The previous users aren't kept, so this can't be undone.
     */
    public function down(): void
    {
        //
    }
};
