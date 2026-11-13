<?php

use App\Models\Guest;
use App\Models\QueueItem;
use App\Models\Room;
use App\Models\Track;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configuration Pest
|--------------------------------------------------------------------------
| Les tests Feature démarrent l'application Laravel et s'exécutent chacun dans une
| transaction annulée à la fin (RefreshDatabase). Les tests Unit sont de purs tests PHP.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Fonctions utilitaires partagées par les tests
|--------------------------------------------------------------------------
*/

/** Crée un salon ouvert et son hôte (Premium par défaut). */
function makeRoom(?User $host = null): Room
{
    return Room::factory()->create(['host_user_id' => ($host ?? User::factory()->create())->id]);
}

/** Crée un invité dans un salon. */
function makeGuest(Room $room, string $nickname = 'Léa'): Guest
{
    return Guest::factory()->create(['room_id' => $room->id, 'nickname' => $nickname]);
}

/**
 * En-têtes d'un appel d'API fait par un invité.
 *
 * @return array<string, string>
 */
function guestHeaders(Guest $guest): array
{
    return ['X-Guest-Token' => $guest->token, 'Accept' => 'application/json'];
}

/**
 * Ajoute $count morceaux à la file via le vrai service (positions attribuées sous verrou).
 *
 * @return list<QueueItem>
 */
function queueTracks(Room $room, int $count, ?Guest $guest = null): array
{
    $guest ??= makeGuest($room);
    $queue = app(QueueService::class);
    $items = [];

    foreach (Track::factory()->count($count)->create() as $track) {
        $items[] = $queue->add($room, $track, $guest);
    }

    return $items;
}
