<?php

// tests/Feature/ResyncTest.php — GET /api/rooms/{code}/state : l'état complet permet de rattraper n'importe quel retard.

use App\Enums\QueueItemStatus;
use App\Models\Room;
use App\Models\Track;
use App\Models\User;
use App\Services\PlaybackService;
use App\Services\QueueService;

it('renvoie un état initial vide et cohérent', function () {
    $this->freezeTime();
    $room = makeRoom();

    $this->getJson("/api/rooms/{$room->code}/state")
        ->assertOk()
        ->assertExactJson([
            'code' => $room->code,
            'status' => 'open',
            'version' => 0,
            'server_time' => now()->getTimestampMs(),
            'spotify_enabled' => true,
            'playback' => ['status' => 'idle', 'current' => null, 'position_ms' => 0, 'position_updated_at' => null],
            'queue' => [],
        ]);
});

it('reflète exactement le résultat de plusieurs mutations successives', function () {
    $this->freezeTime();
    $room = makeRoom();
    $queue = app(QueueService::class);
    $playback = app(PlaybackService::class);

    // Un client connecté au début rate tout ce qui suit (téléphone en veille, Wi-Fi coupé…).
    $versionSeenByClient = $this->getJson("/api/rooms/{$room->code}/state")->json('version');

    [$a, $b, $c, $d] = queueTracks($room, 4);        // v1..v4
    $playback->play($room);                           // v5 : a joue
    $queue->reorder($room, [$d->id, $b->id, $c->id]); // v6
    $queue->remove($room, $b->id);                    // v7
    $queue->advance($room, $a->id, QueueItemStatus::Played); // v8 : d joue
    $this->travel(5)->seconds();
    $playback->pause($room);                          // v9 : pause à 5 s

    $state = $this->getJson("/api/rooms/{$room->code}/state")->assertOk();

    expect($versionSeenByClient)->toBe(0);

    $state
        ->assertJsonPath('version', 9)
        ->assertJsonPath('playback.status', 'paused')
        ->assertJsonPath('playback.current.id', $d->id)
        ->assertJsonPath('playback.position_ms', 5000)
        ->assertJsonCount(1, 'queue')
        ->assertJsonPath('queue.0.id', $c->id)
        ->assertJsonPath('server_time', now()->getTimestampMs());

    // La version annoncée est bien celle stockée : le client peut reprendre le fil des events à 10.
    expect($room->fresh()->version)->toBe(9);
});

it('expose la structure attendue par les types TypeScript', function () {
    $room = makeRoom();
    queueTracks($room, 2);
    app(PlaybackService::class)->play($room);

    $this->getJson("/api/rooms/{$room->code}/state")
        ->assertOk()
        ->assertJsonStructure([
            'code', 'status', 'version', 'server_time', 'spotify_enabled',
            'playback' => [
                'status', 'position_ms', 'position_updated_at',
                'current' => ['id', 'position', 'status', 'added_by' => ['type', 'name'],
                    'track' => ['id', 'provider', 'provider_track_id', 'title', 'artist', 'album_art_url', 'duration_ms']],
            ],
            'queue' => [['id', 'position', 'status', 'track', 'added_by']],
        ]);
});

it('est accessible sans authentification et n\'expose ni jeton ni identifiant d\'invité', function () {
    $room = makeRoom();
    $guest = makeGuest($room);
    app(QueueService::class)->add($room, Track::factory()->create(), $guest);

    $body = $this->getJson("/api/rooms/{$room->code}/state")->assertOk()->getContent();

    expect($body)->not->toContain($guest->token)
        ->not->toContain('spotify_access_token')
        ->not->toContain('added_by_guest_id');
});

it('indique que Spotify est désactivé quand l\'hôte n\'est pas Premium', function () {
    $room = makeRoom(User::factory()->free()->create());

    $this->getJson("/api/rooms/{$room->code}/state")->assertJsonPath('spotify_enabled', false);
});

it('répond encore pour un salon fermé, avec son statut', function () {
    $room = Room::factory()->closed()->create();

    $this->getJson("/api/rooms/{$room->code}/state")
        ->assertOk()
        ->assertJsonPath('status', 'closed');
});

it('valide l\'action même si Reverb est injoignable : le resync rattrapera', function () {
    // Vraie diffusion (pas de Event::fake) vers un port où personne n'écoute.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
    ]);
    $room = makeRoom();
    $track = Track::factory()->create();

    $this->postJson("/api/rooms/{$room->code}/queue", [
        'provider' => $track->provider->value,
        'provider_track_id' => $track->provider_track_id,
    ], guestHeaders(makeGuest($room)))->assertCreated();

    // L'event n'est jamais parti, mais l'état fait foi.
    $this->getJson("/api/rooms/{$room->code}/state")
        ->assertJsonPath('version', 1)
        ->assertJsonCount(1, 'queue');
});
