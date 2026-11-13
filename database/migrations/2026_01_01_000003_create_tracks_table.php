<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue local des morceaux déjà ajoutés à une file (toutes sources confondues).
 * Un morceau est identifié par le couple (provider, provider_track_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            $table->string('provider_track_id', 64);
            $table->string('title', 512);
            $table->string('artist', 512);
            $table->string('album_art_url', 2048)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamps();

            $table->unique(['provider', 'provider_track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
