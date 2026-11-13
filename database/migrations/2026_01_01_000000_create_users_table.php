<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des hôtes. Un "user" est toujours un compte Spotify : pas d'e-mail ni de
 * mot de passe applicatif (l'authentification passe uniquement par OAuth Spotify).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('spotify_id')->unique();
            $table->string('name');
            $table->string('avatar', 2048)->nullable();
            // "premium", "free", "open"… : conditionne l'activation de la lecture Spotify.
            $table->string('spotify_product', 32)->nullable();
            // Colonnes "text" : les jetons sont chiffrés (cast `encrypted`), donc bien plus longs que le jeton brut.
            $table->text('spotify_access_token')->nullable();
            $table->text('spotify_refresh_token')->nullable();
            $table->timestamp('spotify_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
