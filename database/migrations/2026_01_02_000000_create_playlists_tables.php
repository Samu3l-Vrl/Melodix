<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Playlist « de fond » d'un salon : elle joue quand la file des invités est vide.
 *
 * - playlists       : la playlist importée (une par salon à un instant donné, l'historique est conservé).
 * - playlist_tracks : ses morceaux, dans l'ordre de lecture (déjà mélangé si l'hôte a choisi l'aléatoire).
 * - rooms           : playlist active + curseur (index du prochain morceau à jouer).
 * - queue_items     : provenance de chaque élément (invité, hôte, playlist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('provider_playlist_id', 128);
            $table->string('name', 512);
            $table->string('image_url', 2048)->nullable();
            $table->boolean('shuffled')->default(false);
            $table->timestamps();
        });

        Schema::create('playlist_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->restrictOnDelete();
            // 0, 1, 2… : ordre de lecture.
            $table->unsignedInteger('position');

            $table->unique(['playlist_id', 'position']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('playlist_id')->nullable()->after('current_queue_item_id')
                ->constrained('playlists')->nullOnDelete();
            // Index (dans playlist_tracks.position) du prochain morceau de playlist à jouer.
            $table->unsignedInteger('playlist_position')->default(0)->after('playlist_id');
        });

        Schema::table('queue_items', function (Blueprint $table) {
            // guest | host | playlist : sert à l'affichage de l'auteur.
            $table->string('source', 16)->default('guest')->after('added_by_user_id');
            $table->foreignId('playlist_id')->nullable()->after('source')
                ->constrained('playlists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('playlist_id');
            $table->dropColumn('source');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('playlist_id');
            $table->dropColumn('playlist_position');
        });

        Schema::dropIfExists('playlist_tracks');
        Schema::dropIfExists('playlists');
    }
};
