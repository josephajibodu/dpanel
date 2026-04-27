<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_domains', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->string('type', 32);
            $table->boolean('is_primary')->default(false);
            $table->boolean('wildcard_enabled')->default(false);
            $table->string('www_redirect', 32)->default('none');
            $table->boolean('is_enabled')->default(true);
            $table->string('cloudflare_dns_record_id')->nullable();
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->string('root_path')->nullable()->after('domain');
        });

        $freeDomain = config('server.free_domain');

        foreach (DB::table('sites')->orderBy('id')->get() as $site) {
            $aliases = $site->aliases ? json_decode($site->aliases, true) : [];
            if (! is_array($aliases)) {
                $aliases = [];
            }

            $primaryType = self::domainTypeForHostname($site->domain, $freeDomain);

            DB::table('site_domains')->insert([
                'ulid' => (string) Str::ulid(),
                'site_id' => $site->id,
                'hostname' => $site->domain,
                'type' => $primaryType,
                'is_primary' => true,
                'wildcard_enabled' => false,
                'www_redirect' => 'none',
                'is_enabled' => true,
                'cloudflare_dns_record_id' => $site->cloudflare_dns_record_id ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($aliases as $alias) {
                if (! is_string($alias) || $alias === '' || $alias === $site->domain) {
                    continue;
                }
                DB::table('site_domains')->insert([
                    'ulid' => (string) Str::ulid(),
                    'site_id' => $site->id,
                    'hostname' => $alias,
                    'type' => self::domainTypeForHostname($alias, $freeDomain),
                    'is_primary' => false,
                    'wildcard_enabled' => false,
                    'www_redirect' => 'none',
                    'is_enabled' => true,
                    'cloudflare_dns_record_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('sites')->update(['root_path' => DB::raw('domain')]);

        if (Schema::hasColumn('sites', 'cloudflare_dns_record_id')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropColumn('cloudflare_dns_record_id');
            });
        }
    }

    private static function domainTypeForHostname(string $hostname, string $freeDomain): string
    {
        $suffix = '.'.$freeDomain;

        return str_ends_with(strtolower($hostname), strtolower($suffix))
            ? 'system'
            : 'custom';
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('cloudflare_dns_record_id')->nullable()->after('domain');
        });

        foreach (DB::table('sites')->orderBy('id')->get() as $site) {
            $primary = DB::table('site_domains')
                ->where('site_id', $site->id)
                ->where('is_primary', true)
                ->first();

            if ($primary) {
                DB::table('sites')->where('id', $site->id)->update([
                    'cloudflare_dns_record_id' => $primary->cloudflare_dns_record_id,
                ]);
            }
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('root_path');
        });

        Schema::dropIfExists('site_domains');
    }
};
