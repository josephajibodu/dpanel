<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Server type (app, web, worker, database, cache, loadbalancer)
            $table->string('type')->default('app')->after('name');

            // OS information
            $table->string('ubuntu_version')->nullable()->after('database_type');

            // Connection health status (successful, failed, unknown)
            $table->string('connection_status')->default('unknown')->after('provisioning_step');

            // Server's own SSH public key (for connecting to repos, other servers)
            $table->text('local_public_key')->nullable()->after('database_password');

            // Server timezone
            $table->string('timezone')->default('UTC')->after('ubuntu_version');

            // User notes/description
            $table->text('notes')->nullable()->after('timezone');

            // Soft archive flag
            $table->boolean('archived')->default(false)->after('notes');

            // Direct link to provider console
            $table->string('cloud_provider_url')->nullable()->after('provider_server_id');

            // Index for archived servers filtering
            $table->index('archived');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['archived']);
            $table->dropIndex(['type']);
            $table->dropColumn([
                'type',
                'ubuntu_version',
                'connection_status',
                'local_public_key',
                'timezone',
                'notes',
                'archived',
                'cloud_provider_url',
            ]);
        });
    }
};
