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
        Schema::create('provider_sizes', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index();
            $table->string('code')->index();
            $table->string('name');
            $table->string('label')->nullable();
            $table->string('memory');
            $table->string('disk');
            $table->unsignedSmallInteger('cpus');
            $table->decimal('price_monthly', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_sizes');
    }
};
