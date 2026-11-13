<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File d'attente d'un salon. `position` est strictement croissante par salon
 * (attribuée sous verrou), ce qui donne le FIFO "premier arrivé, premier servi".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->restrictOnDelete();
            // L'auteur est soit un invité, soit l'hôte (les deux colonnes sont nullables).
            $table->foreignId('added_by_guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('status', 16)->default('queued');
            $table->timestamp('played_at')->nullable();
            $table->timestamps();

            // Requête la plus fréquente : "les éléments queued d'un salon, triés par position".
            $table->index(['room_id', 'status', 'position']);
        });

        // Dépendance circulaire rooms <-> queue_items : la FK est posée une fois les deux tables créées.
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreign('current_queue_item_id')
                ->references('id')->on('queue_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['current_queue_item_id']);
        });

        Schema::dropIfExists('queue_items');
    }
};
