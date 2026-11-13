<?php

namespace Database\Seeders;

use App\Models\Guest;
use App\Models\Room;
use App\Models\Track;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Database\Seeder;

/**
 * Jeu de données de démonstration : un hôte, un salon "DEMO"-like, deux invités
 * et trois morceaux YouTube en file. Pratique pour regarder l'UI sans clés d'API.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $host = User::factory()->create(['name' => 'Hôte de démo']);
        $room = Room::factory()->create(['host_user_id' => $host->id]);
        $guests = Guest::factory()->count(2)->create(['room_id' => $room->id]);

        $queue = app(QueueService::class);

        foreach (Track::factory()->count(3)->create() as $index => $track) {
            $queue->add($room, $track, $guests[$index % 2]);
        }

        $this->command?->info("Salon de démo créé : {$room->code}");
    }
}
