<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des salons. La ligne `rooms` porte l'état de lecture (source de vérité)
 * et sert de verrou (`SELECT … FOR UPDATE`) pour toutes les mutations de la file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            // 4 lettres, alphabet sans I/O (voir RoomCodeGenerator).
            $table->string('code', 4)->unique();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('open');
            $table->string('playback_status', 16)->default('idle');
            // La clé étrangère est ajoutée dans la migration queue_items (dépendance circulaire).
            $table->unsignedBigInteger('current_queue_item_id')->nullable();
            // Position de lecture (ms) au moment `position_updated_at`.
            $table->unsignedInteger('position_ms')->default(0);
            // Précision milliseconde : sert au calcul de progression sans dérive.
            $table->timestamp('position_updated_at', 3)->nullable();
            // Compteur monotone, incrémenté à chaque changement d'état diffusé.
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            // Utilisé par la commande rooms:close-inactive.
            $table->index(['status', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
