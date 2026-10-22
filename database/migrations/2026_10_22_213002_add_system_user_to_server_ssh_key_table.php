<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A key can now go on an isolated site's own Linux user as well as on the
 * server user, so each row records which user it was written to (and the
 * site, when it's a site user). Existing keys are all on the server user.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('server_ssh_key', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('ssh_key_id')->constrained()->cascadeOnDelete();
            $table->string('system_user', 32)->nullable()->after('site_id');
        });

        DB::table('server_ssh_key')->update(['system_user' => config('server.user')]);

        // Rows written without a user are on the server user, as every key was before.
        Schema::table('server_ssh_key', function (Blueprint $table) {
            $table->string('system_user', 32)->nullable(false)->default(config('server.user'))->change();
            $table->unique(['server_id', 'ssh_key_id', 'system_user']);
            $table->dropUnique(['server_id', 'ssh_key_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('server_ssh_key')->where('system_user', '!=', config('server.user'))->delete();

        Schema::table('server_ssh_key', function (Blueprint $table) {
            $table->unique(['server_id', 'ssh_key_id']);
            $table->dropUnique(['server_id', 'ssh_key_id', 'system_user']);
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn('system_user');
        });
    }
};
