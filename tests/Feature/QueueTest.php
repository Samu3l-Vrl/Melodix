<?php

// tests/Feature/QueueTest.php — file d'attente : FIFO, positions, doublons, retrait, réordonnancement, avance.

use App\Enums\PlaybackStatus;
use App\Enums\Provider;
use App\Enums\QueueItemStatus;
use App\Exceptions\DuplicateTrackException;
use App\Exceptions\RoomClosedException;
use App\Models\QueueItem;
use App\Models\Track;
use App\Models\User;
use App\Services\QueueService;
use App\Services\RoomService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('ajoute un morceau connu via l\'API invité et renvoie l\'état complet', function () {
    $room = makeRoom();
    $guest = makeGuest($room, 'Léa');
    $track = Track::factory()->create(['title' => 'One More Time', 'artist' => 'Daft Punk']);

    $this->postJson("/api/rooms/{$room->code}/queue", [
        'provider' => $track->provider->value,
        'provider_track_id' => $track->provider_track_id,
    ], guestHeaders($guest))
        ->assertCreated()
        ->assertJsonPath('version', 1)
        ->assertJsonCount(1, 'queue')
        ->assertJsonPath('queue.0.track.title', 'One More Time')
        ->assertJsonPath('queue.0.added_by', ['type' => 'guest', 'name' => 'Léa'])
        ->assertJsonPath('queue.0.status', 'queued');
});

it('résout les métadonnées depuis le cache de recherche, jamais depuis le client', function () {
    $room = makeRoom();
    $guest = makeGuest($room);

    // Ce que la recherche a mis en cache quelques secondes plus tôt.
    Cache::put('track:youtube:abc123DEF45', [
        'provider' => 'youtube',
        'provider_track_id' => 'abc123DEF45',
        'title' => 'Titre officiel',
        'artist' => 'Artiste officiel',
        'album_art_url' => null,
        'duration_ms' => 200_000,
    ], 600);

    $this->postJson("/api/rooms/{$room->code}/queue", [
        'provider' => 'youtube',
        'provider_track_id' => 'abc123DEF45',
        // Champs forgés par un client malveillant : ils doivent être ignorés.
        'title' => 'FAUX TITRE',
        'duration_ms' => 1,
    ], guestHeaders($guest))
        ->assertCreated()
        ->assertJsonPath('queue.0.track.title', 'Titre officiel')
        ->assertJsonPath('queue.0.track.duration_ms', 200_000);

    Http::assertNothingSent();
});

it('interroge la source quand le morceau n\'est ni en base ni en cache', function () {
    Http::fake([
        'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
            'id' => 'xyz987XYZ12',
            'snippet' => ['title' => 'Clip retrouv&eacute;', 'channelTitle' => 'Artiste - Topic', 'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/x.jpg']]],
            'contentDetails' => ['duration' => 'PT3M20S'],
            'status' => ['embeddable' => true],
        ]]]),
    ]);
    $room = makeRoom();

    $this->postJson("/api/rooms/{$room->code}/queue", ['provider' => 'youtube', 'provider_track_id' => 'xyz987XYZ12'], guestHeaders(makeGuest($room)))
        ->assertCreated()
        ->assertJsonPath('queue.0.track.title', 'Clip retrouvé')
        ->assertJsonPath('queue.0.track.artist', 'Artiste')
        ->assertJsonPath('queue.0.track.duration_ms', 200_000);
});

it('répond 422 quand la source ne connaît pas le morceau', function () {
    Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => []])]);
    $room = makeRoom();

    $this->postJson("/api/rooms/{$room->code}/queue", ['provider' => 'youtube', 'provider_track_id' => 'inconnu0000'], guestHeaders(makeGuest($room)))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'track_unavailable');

    expect($room->fresh()->version)->toBe(0);
});

it('valide la forme de la demande d\'ajout', function (array $payload, string $field) {
    $room = makeRoom();

    $this->postJson("/api/rooms/{$room->code}/queue", $payload, guestHeaders(makeGuest($room)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'source inconnue' => [['provider' => 'deezer', 'provider_track_id' => 'abc'], 'provider'],
    'identifiant manquant' => [['provider' => 'youtube'], 'provider_track_id'],
    'identifiant avec caractères interdits' => [['provider' => 'youtube', 'provider_track_id' => '../etc/passwd'], 'provider_track_id'],
]);

it('refuse un doublon déjà en file avec un 409', function () {
    $room = makeRoom();
    $track = Track::factory()->create();
    $payload = ['provider' => $track->provider->value, 'provider_track_id' => $track->provider_track_id];

    $this->postJson("/api/rooms/{$room->code}/queue", $payload, guestHeaders(makeGuest($room, 'Léa')))->assertCreated();

    // Un AUTRE invité tente d'ajouter le même morceau.
    $this->postJson("/api/rooms/{$room->code}/queue", $payload, guestHeaders(makeGuest($room, 'Noé')))
        ->assertStatus(409)
        ->assertJsonPath('code', 'duplicate_track');

    expect(QueueItem::count())->toBe(1)
        ->and($room->fresh()->version)->toBe(1); // l'échec n'a pas consommé de version
});

it('refuse aussi le doublon du morceau en cours de lecture, mais accepte un morceau déjà joué', function () {
    $room = makeRoom();
    $guest = makeGuest($room);
    $queue = app(QueueService::class);
    $track = Track::factory()->create();

    $item = $queue->add($room, $track, $guest);
    $queue->advance($room, null, QueueItemStatus::Played); // le morceau passe "playing"

    expect(fn () => $queue->add($room, $track, $guest))->toThrow(DuplicateTrackException::class);

    $queue->advance($room, $item->id, QueueItemStatus::Played); // il est maintenant "played"

    expect($queue->add($room, $track, $guest)->status)->toBe(QueueItemStatus::Queued);
});

it('autorise le même morceau dans deux salons différents', function () {
    $track = Track::factory()->create();
    $queue = app(QueueService::class);
    $roomA = makeRoom();
    $roomB = makeRoom();

    $queue->add($roomA, $track, makeGuest($roomA));
    $queue->add($roomB, $track, makeGuest($roomB));

    expect(QueueItem::count())->toBe(2);
});

it('attribue des positions strictement croissantes à une rafale d\'ajouts de plusieurs invités', function () {
    $room = makeRoom();
    $guests = [makeGuest($room, 'A'), makeGuest($room, 'B'), makeGuest($room, 'C')];
    $tracks = Track::factory()->count(12)->create();
    $queue = app(QueueService::class);

    DB::enableQueryLog();

    foreach ($tracks as $index => $track) {
        $queue->add($room, $track, $guests[$index % 3]);
    }

    $positions = QueueItem::query()->where('room_id', $room->id)->orderBy('id')->pluck('position')->all();

    // FIFO : l'ordre des positions est l'ordre d'arrivée, sans trou ni doublon.
    expect($positions)->toBe(range(1, 12))
        ->and($room->fresh()->version)->toBe(12);

    // Chaque ajout a bien verrouillé la ligne du salon : c'est ce verrou qui sérialise
    // deux requêtes réellement simultanées (elles liraient sinon le même max(position)).
    $locks = collect(DB::getQueryLog())->filter(
        fn (array $query) => str_contains($query['query'], 'from "rooms"') && str_contains(strtolower($query['query']), 'for update')
    );

    expect($locks)->toHaveCount(12);
});

it('conserve l\'ordre premier arrivé, premier servi à la lecture', function () {
    $room = makeRoom();
    [$first, $second, $third] = queueTracks($room, 3);
    $queue = app(QueueService::class);

    $queue->advance($room, null, QueueItemStatus::Played);
    expect($room->current_queue_item_id)->toBe($first->id);

    $queue->advance($room, $first->id, QueueItemStatus::Played);
    expect($room->current_queue_item_id)->toBe($second->id);

    $queue->advance($room, $second->id, QueueItemStatus::Skipped);
    expect($room->current_queue_item_id)->toBe($third->id)
        ->and($first->fresh()->status)->toBe(QueueItemStatus::Played)
        ->and($first->fresh()->played_at)->not->toBeNull()
        ->and($second->fresh()->status)->toBe(QueueItemStatus::Skipped)
        ->and($third->fresh()->status)->toBe(QueueItemStatus::Playing);
});

it('repasse à l\'arrêt quand la file est vide', function () {
    $room = makeRoom();
    [$only] = queueTracks($room, 1);
    $queue = app(QueueService::class);

    $queue->advance($room, null, QueueItemStatus::Played);
    expect($room->playback_status)->toBe(PlaybackStatus::Playing);

    $queue->advance($room, $only->id, QueueItemStatus::Played);

    expect($room->playback_status)->toBe(PlaybackStatus::Idle)
        ->and($room->current_queue_item_id)->toBeNull();
});

it('rend advance idempotent : un deuxième signal pour le même morceau ne saute rien', function () {
    $room = makeRoom();
    [$first, $second, $third] = queueTracks($room, 3);
    $queue = app(QueueService::class);
    $queue->advance($room, null, QueueItemStatus::Played);
    $versionBefore = $room->version;

    // Le lecteur émet deux fois "ended" pour le premier morceau.
    expect($queue->advance($room, $first->id, QueueItemStatus::Played))->toBeTrue()
        ->and($queue->advance($room, $first->id, QueueItemStatus::Played))->toBeFalse();

    expect($room->current_queue_item_id)->toBe($second->id)
        ->and($third->fresh()->status)->toBe(QueueItemStatus::Queued)
        ->and($room->version)->toBe($versionBefore + 1);
});

it('ne crée pas de version quand il n\'y a rien à avancer', function () {
    $room = makeRoom();

    expect(app(QueueService::class)->advance($room, null, QueueItemStatus::Played))->toBeFalse()
        ->and($room->fresh()->version)->toBe(0);
});

it('laisse l\'hôte retirer un morceau en attente', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);
    [$first, $second] = queueTracks($room, 2);

    $this->actingAs($host)->deleteJson("/host/rooms/{$room->code}/queue/{$first->id}")
        ->assertOk()
        ->assertJsonCount(1, 'queue')
        ->assertJsonPath('queue.0.id', $second->id)
        ->assertJsonPath('version', 3);

    expect(QueueItem::find($first->id))->toBeNull();

    // Deuxième clic sur le même bouton : conflit, sans effet.
    $this->actingAs($host)->deleteJson("/host/rooms/{$room->code}/queue/{$first->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'queue_conflict');
});

it('interdit de retirer le morceau en cours ou un morceau d\'un autre salon', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);
    [$playing] = queueTracks($room, 1);
    app(QueueService::class)->advance($room, null, QueueItemStatus::Played);

    $otherRoom = makeRoom();
    [$foreign] = queueTracks($otherRoom, 1);

    $this->actingAs($host)->deleteJson("/host/rooms/{$room->code}/queue/{$playing->id}")->assertStatus(409);
    $this->actingAs($host)->deleteJson("/host/rooms/{$room->code}/queue/{$foreign->id}")->assertStatus(409);

    expect(QueueItem::count())->toBe(2);
});

it('laisse l\'hôte réordonner la file', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);
    [$a, $b, $c] = queueTracks($room, 3);

    $this->actingAs($host)->putJson("/host/rooms/{$room->code}/queue/reorder", ['order' => [$c->id, $a->id, $b->id]])
        ->assertOk()
        ->assertJsonPath('queue.0.id', $c->id)
        ->assertJsonPath('queue.1.id', $a->id)
        ->assertJsonPath('queue.2.id', $b->id);

    // Les positions existantes sont redistribuées : toujours 1, 2, 3, sans doublon.
    expect(QueueItem::query()->where('room_id', $room->id)->orderBy('position')->pluck('id')->all())
        ->toBe([$c->id, $a->id, $b->id])
        ->and(QueueItem::query()->where('room_id', $room->id)->orderBy('position')->pluck('position')->all())
        ->toBe([1, 2, 3]);

    // Un morceau ajouté ensuite arrive bien en dernier.
    $new = queueTracks($room, 1)[0];
    expect($new->position)->toBe(4);
});

it('refuse un réordonnancement basé sur une file périmée', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);
    [$a, $b] = queueTracks($room, 2);
    queueTracks($room, 1); // un invité ajoute un morceau pendant que l'hôte réordonne

    $this->actingAs($host)->putJson("/host/rooms/{$room->code}/queue/reorder", ['order' => [$b->id, $a->id]])
        ->assertStatus(409)
        ->assertJsonPath('code', 'queue_conflict');

    expect($a->fresh()->position)->toBe(1)->and($b->fresh()->position)->toBe(2);
});

it('refuse les morceaux Spotify quand l\'hôte n\'est pas Premium', function () {
    $room = makeRoom(User::factory()->free()->create());
    $track = Track::factory()->spotify()->create();

    $this->postJson("/api/rooms/{$room->code}/queue", [
        'provider' => Provider::Spotify->value,
        'provider_track_id' => $track->provider_track_id,
    ], guestHeaders(makeGuest($room)))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'provider_disabled');

    expect(QueueItem::count())->toBe(0);
});

it('enregistre l\'hôte comme auteur quand c\'est lui qui ajoute', function () {
    $host = User::factory()->create(['name' => 'Camille']);
    $room = makeRoom($host);

    app(QueueService::class)->add($room, Track::factory()->create(), $host);

    $this->getJson("/api/rooms/{$room->code}/state")
        ->assertJsonPath('queue.0.added_by', ['type' => 'host', 'name' => 'Camille']);
});

it('refuse toute mutation sur un salon fermé', function () {
    $room = makeRoom();
    $guest = makeGuest($room);
    app(RoomService::class)->close($room);

    app(QueueService::class)->add($room, Track::factory()->create(), $guest);
})->throws(RoomClosedException::class);
