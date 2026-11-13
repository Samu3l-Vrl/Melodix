<?php

// tests/Feature/PlaylistTest.php — playlist de fond : import, enchaînement avec la file des invités, gestion par l'hôte.

use App\Data\PlaylistData;
use App\Data\TrackData;
use App\Enums\PlaybackStatus;
use App\Enums\Provider;
use App\Enums\QueueItemSource;
use App\Enums\QueueItemStatus;
use App\Events\PlaybackStateChanged;
use App\Models\Playlist;
use App\Models\QueueItem;
use App\Models\Room;
use App\Models\Track;
use App\Models\User;
use App\Services\PlaybackService;
use App\Services\Playlists\PlaylistService;
use App\Services\QueueService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/** Rattache une playlist de $count morceaux YouTube au salon et la renvoie. */
function attachPlaylist(Room $room, int $count = 3): Playlist
{
    $playlist = Playlist::factory()->withTracks($count)->create(['room_id' => $room->id, 'name' => 'Soirée 2000']);
    $room->update(['playlist_id' => $playlist->id, 'playlist_position' => 0]);

    return $playlist;
}

/** Ids des morceaux de la playlist, dans l'ordre de lecture. */
function playlistTrackIds(Playlist $playlist): array
{
    return DB::table('playlist_tracks')->where('playlist_id', $playlist->id)->orderBy('position')->pluck('track_id')->all();
}

/** Morceau en cours dans le salon. */
function currentTrackId(Room $room): ?int
{
    return $room->fresh()->currentItem?->track_id;
}

/** Fin du morceau courant (comme si le lecteur de l'hôte signalait "ended"). */
function finishCurrent(Room $room): void
{
    $room->refresh();
    app(QueueService::class)->advance($room, $room->current_queue_item_id, QueueItemStatus::Played);
}

// --- Enchaînement ----------------------------------------------------------------------

it('joue la playlist dans l\'ordre quand la file est vide, et recommence au début une fois finie', function () {
    $room = makeRoom();
    $playlist = attachPlaylist($room, 3);
    [$a, $b, $c] = playlistTrackIds($playlist);

    app(PlaybackService::class)->play($room);
    expect(currentTrackId($room))->toBe($a);

    finishCurrent($room);
    expect(currentTrackId($room))->toBe($b);

    finishCurrent($room);
    expect(currentTrackId($room))->toBe($c);

    finishCurrent($room);
    expect(currentTrackId($room))->toBe($a) // boucle
        ->and($room->fresh()->playback_status)->toBe(PlaybackStatus::Playing);
});

it('donne la priorité aux morceaux des invités, puis reprend la playlist là où elle en était', function () {
    $room = makeRoom();
    $playlist = attachPlaylist($room, 3);
    [$a, $b] = playlistTrackIds($playlist);

    app(PlaybackService::class)->play($room); // a (playlist)

    // Deux invités ajoutent un morceau pendant que la playlist joue.
    [$g1, $g2] = queueTracks($room, 2);

    finishCurrent($room);
    expect(currentTrackId($room))->toBe($g1->track_id);

    finishCurrent($room);
    expect(currentTrackId($room))->toBe($g2->track_id);

    finishCurrent($room);
    expect(currentTrackId($room))->toBe($b); // la playlist reprend au morceau suivant, sans rejouer a
});

it('marque les morceaux de la playlist comme tels dans l\'état', function () {
    $room = makeRoom();
    attachPlaylist($room);
    app(PlaybackService::class)->play($room);

    $this->getJson("/api/rooms/{$room->code}/state")
        ->assertJsonPath('playback.current.added_by', ['type' => 'playlist', 'name' => 'Soirée 2000'])
        ->assertJsonPath('playback.playlist.name', 'Soirée 2000')
        ->assertJsonPath('playback.playlist.provider', 'youtube')
        // Les morceaux de la playlist ne sont pas listés « à suivre » : seule la file des invités l'est.
        ->assertJsonCount(0, 'queue');

    expect(QueueItem::query()->sole()->source)->toBe(QueueItemSource::Playlist);
});

it('ne rejoue pas tout de suite un morceau de la playlist déjà demandé par un invité', function () {
    $room = makeRoom();
    $playlist = attachPlaylist($room, 3);
    [$a, $b, $c] = playlistTrackIds($playlist);

    app(PlaybackService::class)->play($room); // a (playlist)
    // Un invité demande b, qui est aussi le prochain titre de la playlist.
    app(QueueService::class)->add($room, Track::find($b), makeGuest($room));

    finishCurrent($room);
    expect(currentTrackId($room))->toBe($b)
        ->and($room->fresh()->currentItem->source)->toBe(QueueItemSource::Guest);

    // La playlist reprend à b… qui vient d'être joué : elle passe directement à c.
    finishCurrent($room);
    expect(currentTrackId($room))->toBe($c);
});

it('joue normalement un morceau de la playlist que l\'hôte a retiré de la file sans qu\'il soit joué', function () {
    $room = makeRoom();
    $playlist = attachPlaylist($room, 3);
    [$a, $b, $c] = playlistTrackIds($playlist);

    app(PlaybackService::class)->play($room); // a
    $guestItem = app(QueueService::class)->add($room, Track::find($b), makeGuest($room));
    // Retiré avant d'avoir joué : ni actif, ni joué récemment.
    app(QueueService::class)->remove($room, $guestItem->id);

    finishCurrent($room);
    expect(currentTrackId($room))->toBe($b);
});

it('saute les morceaux Spotify de la playlist quand l\'hôte n\'est pas Premium', function () {
    $room = makeRoom(User::factory()->free()->create());
    $playlist = Playlist::factory()->create(['room_id' => $room->id]);
    $spotify = Track::factory()->spotify()->create();
    $youtube = Track::factory()->create();
    DB::table('playlist_tracks')->insert([
        ['playlist_id' => $playlist->id, 'track_id' => $spotify->id, 'position' => 0],
        ['playlist_id' => $playlist->id, 'track_id' => $youtube->id, 'position' => 1],
    ]);
    $room->update(['playlist_id' => $playlist->id]);

    app(PlaybackService::class)->play($room);

    expect(currentTrackId($room))->toBe($youtube->id);
});

it('rejoue en boucle une playlist d\'un seul morceau', function () {
    $room = makeRoom();
    $playlist = attachPlaylist($room, 1);
    [$only] = playlistTrackIds($playlist);

    app(PlaybackService::class)->play($room);
    finishCurrent($room);
    finishCurrent($room);

    expect(currentTrackId($room))->toBe($only)
        ->and(QueueItem::query()->where('status', QueueItemStatus::Played)->count())->toBe(2);
});

it('s\'arrête quand la file est vide et qu\'il n\'y a pas de playlist', function () {
    $room = makeRoom();
    [$only] = queueTracks($room, 1);

    app(PlaybackService::class)->play($room);
    finishCurrent($room);

    expect($room->fresh()->playback_status)->toBe(PlaybackStatus::Idle)
        ->and(currentTrackId($room))->toBeNull();
});

// --- Import ----------------------------------------------------------------------------

/** Réponses Spotify : métadonnées + deux pages de morceaux (dont un épisode de podcast et un fichier local). */
function fakeSpotifyPlaylist(string $id = '3cEYpjA9oz9GiPac4AsH4n'): void
{
    $track = fn (string $tid, string $name) => ['track' => [
        'type' => 'track', 'id' => $tid, 'name' => $name, 'duration_ms' => 200_000, 'is_local' => false,
        'artists' => [['name' => 'Daft Punk']], 'album' => ['images' => [['url' => "https://i.scdn.co/image/{$tid}"]]],
    ]];

    Http::fake([
        "api.spotify.com/v1/playlists/{$id}/tracks*" => Http::sequence()
            ->push(['items' => [$track('sp1', 'One More Time'), $track('sp2', 'Aerodynamic')], 'next' => 'https://api.spotify.com/v1/…?offset=100'])
            ->push(['items' => [
                $track('sp3', 'Digital Love'),
                ['track' => ['type' => 'episode', 'id' => 'ep1', 'name' => 'Un podcast', 'duration_ms' => 1_000_000]],
                ['track' => ['type' => 'track', 'id' => null, 'name' => 'Fichier local', 'is_local' => true, 'duration_ms' => 1000]],
            ], 'next' => null]),
        "api.spotify.com/v1/playlists/{$id}*" => Http::response(['id' => $id, 'name' => 'Discovery', 'images' => [['url' => 'https://i.scdn.co/image/cover']]]),
    ]);
}

it('crée un salon avec une playlist Spotify', function () {
    fakeSpotifyPlaylist();
    $host = User::factory()->create(['spotify_access_token' => 'host-token']);

    $this->actingAs($host)
        ->post('/rooms', ['playlist_url' => 'https://open.spotify.com/playlist/3cEYpjA9oz9GiPac4AsH4n?si=x', 'shuffle' => false])
        ->assertRedirect();

    $room = Room::query()->sole();
    $playlist = $room->playlist;

    expect($playlist->name)->toBe('Discovery')
        ->and($playlist->image_url)->toBe('https://i.scdn.co/image/cover')
        ->and($playlist->tracks->pluck('provider_track_id')->all())->toBe(['sp1', 'sp2', 'sp3']) // ni podcast ni fichier local
        ->and($room->playlist_position)->toBe(0);

    // Le jeton PERSONNEL de l'hôte est utilisé (playlists privées), avec le marché configuré.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/playlists/3cEYpjA9oz9GiPac4AsH4n/tracks')
        && $r->hasHeader('Authorization', 'Bearer host-token')
        && $r['market'] === 'FR');
});

it('crée un salon avec une playlist YouTube, en ignorant les vidéos non intégrables', function () {
    Http::fake([
        'www.googleapis.com/youtube/v3/playlists?*' => Http::response(['items' => [['snippet' => ['title' => 'Rock &amp; Roll', 'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/cover.jpg']]]]]]),
        'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => [
            ['contentDetails' => ['videoId' => 'vid00000001']],
            ['contentDetails' => ['videoId' => 'vid00000002']],
            ['contentDetails' => ['videoId' => 'vid00000003']],
        ]]),
        'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [
            ['id' => 'vid00000001', 'snippet' => ['title' => 'Titre 1', 'channelTitle' => 'Groupe - Topic'], 'contentDetails' => ['duration' => 'PT3M'], 'status' => ['embeddable' => true]],
            ['id' => 'vid00000002', 'snippet' => ['title' => 'Bloquée'], 'contentDetails' => ['duration' => 'PT3M'], 'status' => ['embeddable' => false]],
            ['id' => 'vid00000003', 'snippet' => ['title' => 'Titre 3', 'channelTitle' => 'Groupe'], 'contentDetails' => ['duration' => 'PT4M'], 'status' => ['embeddable' => true]],
        ]]),
    ]);
    $host = User::factory()->free()->create(); // YouTube fonctionne sans Premium

    $this->actingAs($host)
        ->post('/rooms', ['playlist_url' => 'https://www.youtube.com/playlist?list=PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI'])
        ->assertRedirect();

    $playlist = Room::query()->sole()->playlist;

    expect($playlist->name)->toBe('Rock & Roll')
        ->and($playlist->tracks->pluck('provider_track_id')->all())->toBe(['vid00000001', 'vid00000003'])
        ->and($playlist->tracks->first()->artist)->toBe('Groupe');
});

it('mélange la playlist à l\'import quand l\'hôte le demande', function () {
    $tracks = collect(range(1, 40))->map(fn ($i) => ['contentDetails' => ['videoId' => sprintf('vid%08d', $i)]])->all();
    $videos = collect(range(1, 40))->map(fn ($i) => [
        'id' => sprintf('vid%08d', $i), 'snippet' => ['title' => "Titre {$i}", 'channelTitle' => 'X'],
        'contentDetails' => ['duration' => 'PT3M'], 'status' => ['embeddable' => true],
    ])->all();
    Http::fake([
        'www.googleapis.com/youtube/v3/playlists?*' => Http::response(['items' => [['snippet' => ['title' => 'Mix']]]]),
        'www.googleapis.com/youtube/v3/playlistItems*' => Http::response(['items' => $tracks]),
        'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => $videos]),
    ]);

    $this->actingAs(User::factory()->create())
        ->post('/rooms', ['playlist_url' => 'https://www.youtube.com/playlist?list=PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI', 'shuffle' => true]);

    $playlist = Room::query()->sole()->playlist;
    $order = $playlist->tracks->pluck('provider_track_id')->all();

    expect($playlist->shuffled)->toBeTrue()
        ->and($order)->toHaveCount(40)
        ->and($order)->toEqualCanonicalizing(array_column($videos, 'id'))
        ->and($order)->not->toBe(array_column($videos, 'id')); // 1 chance sur 40! d'échouer par hasard
});

it('refuse un lien non reconnu sans créer de salon', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->from('/')
        ->post('/rooms', ['playlist_url' => 'https://open.spotify.com/album/3cEYpjA9oz9GiPac4AsH4n'])
        ->assertRedirect('/')
        ->assertSessionHasErrors('playlist_url');

    expect(Room::count())->toBe(0);
    Http::assertNothingSent();
});

it('explique pourquoi une playlist Spotify éditoriale est refusée', function () {
    Http::fake(['api.spotify.com/*' => Http::response(['error' => ['status' => 404, 'message' => 'Resource not found']], 404)]);

    $this->actingAs(User::factory()->create())
        ->from('/')
        ->post('/rooms', ['playlist_url' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M'])
        ->assertSessionHasErrors(['playlist_url' => 'Playlist introuvable. Vérifiez le lien. Spotify ne donne pas accès à ses playlists éditoriales ou générées (« Daily Mix », « Top 50 »…) : utilisez une playlist créée par vous ou par un autre utilisateur.']);

    expect(Room::count())->toBe(0);
});

it('refuse une playlist Spotify quand l\'hôte n\'est pas Premium', function () {
    Http::fake();

    $this->actingAs(User::factory()->free()->create())
        ->from('/')
        ->post('/rooms', ['playlist_url' => 'https://open.spotify.com/playlist/3cEYpjA9oz9GiPac4AsH4n'])
        ->assertSessionHasErrors('playlist_url');

    Http::assertNothingSent();
});

it('crée toujours un salon sans playlist', function () {
    $this->actingAs(User::factory()->create())->post('/rooms')->assertRedirect();

    expect(Room::query()->sole()->playlist_id)->toBeNull();
});

it('n\'importe pas plus de morceaux que la limite configurée', function () {
    config(['jukebox.playlist.max_tracks' => 2]);
    fakeSpotifyPlaylist();

    $this->actingAs(User::factory()->create())
        ->post('/rooms', ['playlist_url' => 'spotify:playlist:3cEYpjA9oz9GiPac4AsH4n']);

    expect(Room::query()->sole()->playlist->tracks)->toHaveCount(2);
});

// --- Gestion par l'hôte -----------------------------------------------------------------

it('permet à l\'hôte de changer de playlist, et le diffuse', function () {
    Event::fake([PlaybackStateChanged::class]);
    fakeSpotifyPlaylist();
    $host = User::factory()->create();
    $room = makeRoom($host);
    attachPlaylist($room)->update(['name' => 'Ancienne']);

    $this->actingAs($host)
        ->putJson("/host/rooms/{$room->code}/playlist", ['playlist_url' => 'https://open.spotify.com/playlist/3cEYpjA9oz9GiPac4AsH4n'])
        ->assertOk()
        ->assertJsonPath('playback.playlist.name', 'Discovery')
        ->assertJsonPath('version', 1);

    expect($room->fresh()->playlist_position)->toBe(0);
    Event::assertDispatched(PlaybackStateChanged::class, fn ($e) => $e->version === 1 && $e->playback['playlist']['name'] === 'Discovery');
});

it('renvoie le message d\'erreur à l\'hôte quand la nouvelle playlist est inutilisable', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);

    $this->actingAs($host)
        ->putJson("/host/rooms/{$room->code}/playlist", ['playlist_url' => 'pas un lien'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'playlist_unavailable');
});

it('permet à l\'hôte de retirer la playlist : la musique s\'arrête à la fin de la file', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);
    attachPlaylist($room);
    app(PlaybackService::class)->play($room);

    $this->actingAs($host)->deleteJson("/host/rooms/{$room->code}/playlist")
        ->assertOk()
        ->assertJsonPath('playback.playlist', null)
        // Le morceau en cours finit de jouer.
        ->assertJsonPath('playback.status', 'playing');

    finishCurrent($room);

    expect($room->fresh()->playback_status)->toBe(PlaybackStatus::Idle);
});

it('réserve la gestion de la playlist au propriétaire du salon', function () {
    $room = makeRoom();

    $this->actingAs(User::factory()->create())
        ->putJson("/host/rooms/{$room->code}/playlist", ['playlist_url' => 'spotify:playlist:3cEYpjA9oz9GiPac4AsH4n'])
        ->assertForbidden();

    $this->actingAs(User::factory()->create())->deleteJson("/host/rooms/{$room->code}/playlist")->assertForbidden();
});

it('refuse la gestion de la playlist à un invité', function () {
    $room = makeRoom();

    $this->putJson("/host/rooms/{$room->code}/playlist", ['playlist_url' => 'x'], guestHeaders(makeGuest($room)))->assertUnauthorized();
    $this->deleteJson("/host/rooms/{$room->code}/playlist", [], guestHeaders(makeGuest($room)))->assertUnauthorized();
});

it('liste les playlists Spotify de l\'hôte', function () {
    Http::fake(['api.spotify.com/v1/me/playlists*' => Http::response(['items' => [
        ['id' => 'pl1', 'name' => 'Apéro', 'images' => [['url' => 'https://i.scdn.co/a']], 'tracks' => ['total' => 42], 'owner' => ['display_name' => 'Camille']],
        ['id' => 'pl2', 'name' => 'Sans image', 'images' => null, 'items' => ['total' => 7], 'owner' => ['display_name' => 'Léa']],
    ]])]);

    $this->actingAs(User::factory()->create())->getJson('/host/spotify/playlists')
        ->assertOk()
        ->assertExactJson(['playlists' => [
            ['id' => 'pl1', 'name' => 'Apéro', 'image_url' => 'https://i.scdn.co/a', 'track_count' => 42, 'owner' => 'Camille'],
            ['id' => 'pl2', 'name' => 'Sans image', 'image_url' => null, 'track_count' => 7, 'owner' => 'Léa'],
        ]]);
});

it('ne liste aucune playlist Spotify pour un hôte non Premium', function () {
    Http::fake();

    $this->actingAs(User::factory()->free()->create())->getJson('/host/spotify/playlists')
        ->assertOk()
        ->assertExactJson(['playlists' => []]);

    Http::assertNothingSent();
});

it('demande les scopes de lecture des playlists à la connexion', function () {
    $location = $this->get('/auth/spotify/redirect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(explode(' ', $query['scope']))->toContain('playlist-read-private', 'playlist-read-collaborative');
});

it('enregistre une playlist contenant deux fois le même morceau', function () {
    $room = makeRoom();
    $track = new TrackData(Provider::YouTube, 'dup00000001', 'Titre', 'Artiste', null, 180_000);
    $data = new PlaylistData(Provider::YouTube, 'PLx', 'Doublons', null, [$track, $track]);

    $playlist = app(PlaylistService::class)->attach($room, $data, false);

    expect(DB::table('playlist_tracks')->where('playlist_id', $playlist->id)->count())->toBe(2)
        ->and(Track::count())->toBe(1);
});
