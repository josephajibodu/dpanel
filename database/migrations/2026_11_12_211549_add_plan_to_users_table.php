<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The plan the user is on (see App\Enums\Plan). Every team the user owns
     * takes its limits from it. Everyone starts on the free Hobby plan.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan')->default('hobby')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('plan');
        });
    }
};
