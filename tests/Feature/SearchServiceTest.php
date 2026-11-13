<?php

// tests/Feature/SearchServiceTest.php — recherche unifiée : parallélisme, dégradation, cache, quota, filtrage Premium.

use App\Data\SearchOutcome;
use App\Data\TrackData;
use App\Enums\Provider;
use App\Models\User;
use App\Services\Music\SearchService;
use App\Services\Music\YouTubeQuota;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Réponse type de POST accounts.spotify.com/api/token (client credentials). */
function spotifyTokenResponse(): array
{
    return ['access_token' => 'app-token', 'token_type' => 'Bearer', 'expires_in' => 3600];
}

/** Réponse type de GET api.spotify.com/v1/search. */
function spotifySearchResponse(array $tracks): array
{
    return ['tracks' => ['items' => array_map(fn (array $t) => [
        'id' => $t['id'],
        'name' => $t['title'],
        'duration_ms' => $t['duration_ms'] ?? 200_000,
        'artists' => array_map(fn (string $name) => ['name' => $name], (array) $t['artist']),
        'album' => ['images' => [['url' => "https://i.scdn.co/image/{$t['id']}"], ['url' => 'https://i.scdn.co/image/small']]],
        'is_playable' => $t['is_playable'] ?? true,
    ], $tracks)]];
}

/** Réponse type de GET youtube/v3/search (les durées n'y figurent pas). */
function youtubeSearchResponse(array $videos): array
{
    return ['items' => array_map(fn (array $v) => [
        'id' => ['kind' => 'youtube#video', 'videoId' => $v['id']],
        'snippet' => [
            'title' => $v['title'],
            'channelTitle' => $v['channel'],
            'thumbnails' => ['high' => ['url' => "https://i.ytimg.com/vi/{$v['id']}/hqdefault.jpg"]],
        ],
    ], $videos)];
}

/** Réponse type de GET youtube/v3/videos?part=contentDetails. */
function youtubeVideosResponse(array $durations): array
{
    return ['items' => collect($durations)->map(fn (string $iso, string $id) => [
        'id' => $id,
        'contentDetails' => ['duration' => $iso],
    ])->values()->all()];
}

/** Simule les deux sources en bon état. */
function fakeBothProviders(): void
{
    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(spotifyTokenResponse()),
        'api.spotify.com/v1/search*' => Http::response(spotifySearchResponse([
            ['id' => 'sp1', 'title' => 'One More Time', 'artist' => 'Daft Punk', 'duration_ms' => 320_357],
            ['id' => 'sp2', 'title' => 'Around the World', 'artist' => 'Daft Punk'],
            ['id' => 'sp3', 'title' => 'Get Lucky', 'artist' => ['Daft Punk', 'Pharrell Williams']],
        ])),
        'www.googleapis.com/youtube/v3/search*' => Http::response(youtubeSearchResponse([
            ['id' => 'yt1', 'title' => 'Daft Punk - One More Time (Official Video)', 'channel' => 'Daft Punk'],
            ['id' => 'yt2', 'title' => 'Daft Punk - Harder, Better, Faster, Stronger', 'channel' => 'Daft Punk'],
            ['id' => 'yt3', 'title' => 'Rock &amp; Roll (Live)', 'channel' => 'Some Band - Topic'],
            ['id' => 'yt4', 'title' => 'Radio en direct 24/7', 'channel' => 'Lofi'],
        ])),
        'www.googleapis.com/youtube/v3/videos*' => Http::response(youtubeVideosResponse([
            'yt1' => 'PT5M21S', 'yt2' => 'PT3M45S', 'yt3' => 'PT1H2M3S', 'yt4' => 'P0D',
        ])),
    ]);
}

beforeEach(function () {
    $this->room = makeRoom();
    $this->guest = makeGuest($this->room);
    $this->search = fn (string $q) => $this->getJson("/api/rooms/{$this->room->code}/search?q=".urlencode($q), guestHeaders($this->guest));
});

it('fusionne Spotify et YouTube en entrelaçant les résultats et en retirant les doublons', function () {
    fakeBothProviders();

    $response = ($this->search)('daft punk')->assertOk();

    expect($response->json('sources'))->toBe(['spotify' => 'ok', 'youtube' => 'ok']);

    // Entrelacement : sp1, (yt1 = même chanson que sp1 -> retirée), sp2, yt2, sp3, yt3. yt4 (direct) est écartée.
    expect(collect($response->json('results'))->pluck('provider_track_id')->all())
        ->toBe(['sp1', 'sp2', 'yt2', 'sp3', 'yt3']);

    expect($response->json('results.0'))->toBe([
        'provider' => 'spotify',
        'provider_track_id' => 'sp1',
        'title' => 'One More Time',
        'artist' => 'Daft Punk',
        'album_art_url' => 'https://i.scdn.co/image/sp1',
        'duration_ms' => 320_357,
    ]);

    // Plusieurs artistes Spotify sont concaténés.
    expect($response->json('results.3.artist'))->toBe('Daft Punk, Pharrell Williams');

    // YouTube : entités HTML décodées, suffixe "- Topic" retiré, durée ISO 8601 convertie.
    expect($response->json('results.4'))->toMatchArray([
        'provider' => 'youtube',
        'title' => 'Rock & Roll (Live)',
        'artist' => 'Some Band',
        'duration_ms' => 3_723_000,
    ]);
});

it('envoie aux sources les paramètres attendus', function () {
    fakeBothProviders();

    ($this->search)('Daft Punk')->assertOk();

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.spotify.com/v1/search')
        && $r->hasHeader('Authorization', 'Bearer app-token')
        && $r['type'] === 'track'
        && $r['market'] === 'FR'
        && $r['q'] === 'daft punk');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://www.googleapis.com/youtube/v3/search')
        && $r['videoEmbeddable'] === 'true'
        && $r['videoCategoryId'] === '10'
        && $r['type'] === 'video'
        && $r['key'] === 'test-youtube-key');

    // Un SEUL videos.list groupé pour toutes les durées.
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://www.googleapis.com/youtube/v3/videos')
        && $r['id'] === 'yt1,yt2,yt3,yt4'
        && $r['part'] === 'contentDetails');

    Http::assertSentCount(4); // jeton Spotify + recherche Spotify + search.list + videos.list
});

it('répond avec YouTube seul quand Spotify est en panne', function () {
    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(spotifyTokenResponse()),
        'api.spotify.com/v1/search*' => Http::response(['error' => ['status' => 503]], 503),
        'www.googleapis.com/youtube/v3/search*' => Http::response(youtubeSearchResponse([
            ['id' => 'yt1', 'title' => 'Daft Punk - One More Time', 'channel' => 'Daft Punk'],
        ])),
        'www.googleapis.com/youtube/v3/videos*' => Http::response(youtubeVideosResponse(['yt1' => 'PT5M21S'])),
    ]);

    $response = ($this->search)('daft punk')->assertOk();

    expect($response->json('sources'))->toBe(['spotify' => 'error', 'youtube' => 'ok'])
        ->and($response->json('results'))->toHaveCount(1)
        ->and($response->json('results.0.provider'))->toBe('youtube');
});

it('répond avec Spotify seul quand YouTube ne répond pas (timeout)', function () {
    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(spotifyTokenResponse()),
        'api.spotify.com/v1/search*' => Http::response(spotifySearchResponse([
            ['id' => 'sp1', 'title' => 'One More Time', 'artist' => 'Daft Punk'],
        ])),
        // Ce que Guzzle lève réellement quand le timeout de 4 s est dépassé.
        'www.googleapis.com/youtube/v3/search*' => fn () => throw new ConnectException(
            'cURL error 28: Operation timed out after 4001 milliseconds',
            new GuzzleRequest('GET', 'https://www.googleapis.com/youtube/v3/search'),
        ),
    ]);

    $response = ($this->search)('daft punk')->assertOk();

    expect($response->json('sources'))->toBe(['spotify' => 'ok', 'youtube' => 'error'])
        ->and(collect($response->json('results'))->pluck('provider_track_id')->all())->toBe(['sp1']);
});

it('répond avec YouTube seul quand le jeton d\'application Spotify ne peut pas être obtenu', function () {
    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(['error' => 'invalid_client'], 400),
        'www.googleapis.com/youtube/v3/search*' => Http::response(youtubeSearchResponse([
            ['id' => 'yt1', 'title' => 'Clip', 'channel' => 'Chaîne'],
        ])),
        'www.googleapis.com/youtube/v3/videos*' => Http::response(youtubeVideosResponse(['yt1' => 'PT3M'])),
    ]);

    $response = ($this->search)('clip')->assertOk();

    expect($response->json('sources'))->toBe(['spotify' => 'error', 'youtube' => 'ok'])
        ->and($response->json('results'))->toHaveCount(1);
});

it('renvoie une liste vide, sans erreur, quand les deux sources échouent', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    ($this->search)('daft punk')
        ->assertOk()
        ->assertExactJson(['results' => [], 'sources' => ['spotify' => 'error', 'youtube' => 'error']]);
});

it('sert la deuxième recherche identique depuis le cache, sans rappeler les API', function () {
    fakeBothProviders();

    $first = ($this->search)('daft punk')->assertOk()->json('results');
    Http::assertSentCount(4);

    // Même requête, casse et espaces différents : même entrée de cache.
    $second = ($this->search)('  DAFT   Punk ')->assertOk()->json('results');

    Http::assertSentCount(4);
    expect($second)->toBe($first);

    // Une requête différente repart vers les API (le jeton Spotify, lui, reste en cache).
    ($this->search)('justice')->assertOk();
    Http::assertSentCount(7);
});

it('ne met pas en cache l\'échec d\'une source', function () {
    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(spotifyTokenResponse()),
        'api.spotify.com/v1/search*' => Http::sequence()
            ->push(['error' => 'down'], 500)
            ->push(spotifySearchResponse([['id' => 'sp1', 'title' => 'One More Time', 'artist' => 'Daft Punk']])),
        'www.googleapis.com/youtube/v3/search*' => Http::response(youtubeSearchResponse([])),
    ]);

    expect(($this->search)('daft punk')->json('sources.spotify'))->toBe('error');
    // Spotify est revenu : la recherche suivante le réinterroge (YouTube, lui, vient du cache).
    expect(($this->search)('daft punk')->json())->toMatchArray(['sources' => ['spotify' => 'ok', 'youtube' => 'ok']]);
});

it('mémorise chaque résultat pour l\'ajout en file', function () {
    fakeBothProviders();

    ($this->search)('daft punk')->assertOk();

    expect(Cache::get('track:spotify:sp2'))->toMatchArray(['title' => 'Around the World'])
        ->and(Cache::get('track:youtube:yt2'))->toMatchArray(['duration_ms' => 225_000]);
});

it('n\'interroge pas Spotify et le signale désactivé quand l\'hôte n\'est pas Premium', function () {
    fakeBothProviders();
    $room = makeRoom(User::factory()->free()->create());

    $response = $this->getJson("/api/rooms/{$room->code}/search?q=daft+punk", guestHeaders(makeGuest($room)))->assertOk();

    expect($response->json('sources'))->toBe(['spotify' => 'disabled', 'youtube' => 'ok'])
        ->and(collect($response->json('results'))->pluck('provider')->unique()->all())->toBe(['youtube']);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'spotify.com'));
});

it('ignore une source dont la clé d\'API n\'est pas configurée', function () {
    fakeBothProviders();
    config(['services.youtube.key' => null]);

    $response = ($this->search)('daft punk')->assertOk();

    expect($response->json('sources'))->toBe(['spotify' => 'ok', 'youtube' => 'disabled']);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'googleapis.com'));
});

it('écarte les morceaux Spotify non lisibles dans le pays', function () {
    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(spotifyTokenResponse()),
        'api.spotify.com/v1/search*' => Http::response(spotifySearchResponse([
            ['id' => 'sp1', 'title' => 'Bloqué', 'artist' => 'X', 'is_playable' => false],
            ['id' => 'sp2', 'title' => 'Lisible', 'artist' => 'Y'],
        ])),
        'www.googleapis.com/youtube/v3/search*' => Http::response(youtubeSearchResponse([])),
    ]);

    expect(collect(($this->search)('test')->json('results'))->pluck('provider_track_id')->all())->toBe(['sp2']);
});

it('compte le quota YouTube : 100 unités par recherche + 1 pour les durées', function () {
    fakeBothProviders();
    $quota = app(YouTubeQuota::class);

    ($this->search)('daft punk')->assertOk();
    expect($quota->used())->toBe(101);

    // Depuis le cache : aucune unité consommée.
    ($this->search)('daft punk')->assertOk();
    expect($quota->used())->toBe(101);
});

it('cesse d\'appeler YouTube quand le budget du jour est épuisé', function () {
    fakeBothProviders();
    config(['services.youtube.daily_quota' => 150]);

    expect(($this->search)('premiere')->json('sources.youtube'))->toBe('ok');   // 101 unités
    expect(($this->search)('deuxieme')->json('sources.youtube'))->toBe('error'); // 101 + 101 > 150

    Http::assertSentCount(5); // la 2e recherche n'a appelé que Spotify
});

it('retient l\'épuisement du quota annoncé par Google (403 quotaExceeded)', function () {
    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(spotifyTokenResponse()),
        'api.spotify.com/v1/search*' => Http::response(spotifySearchResponse([])),
        'www.googleapis.com/youtube/v3/search*' => Http::response(
            ['error' => ['code' => 403, 'errors' => [['reason' => 'quotaExceeded']]]], 403
        ),
    ]);

    expect(($this->search)('premiere')->json('sources.youtube'))->toBe('error');
    expect(app(YouTubeQuota::class)->canSpend(1))->toBeFalse();

    ($this->search)('deuxieme');
    Http::assertSentCount(4); // jeton + 2 recherches Spotify + 1 seul appel YouTube
});

it('exige au moins 3 caractères', function (string $q) {
    Http::fake();

    ($this->search)($q)->assertUnprocessable()->assertJsonValidationErrors('q');

    Http::assertNothingSent();
})->with(['', 'a', 'ab', '  ab  ']);

it('garde les variantes d\'un même titre au sein d\'une source, mais dédoublonne entre sources', function () {
    $track = fn (Provider $p, string $id, string $title, string $artist) => new TrackData($p, $id, $title, $artist, null, 200_000);

    expect(SearchService::fingerprint($track(Provider::Spotify, 'a', 'One More Time', 'Daft Punk')))
        ->toBe(SearchService::fingerprint($track(Provider::YouTube, 'b', 'Daft Punk - One More Time (Official Video) [HD]', 'Daft Punk')))
        ->not->toBe(SearchService::fingerprint($track(Provider::Spotify, 'c', 'Around the World', 'Daft Punk')));

    Http::fake([
        'accounts.spotify.com/api/token' => Http::response(spotifyTokenResponse()),
        'api.spotify.com/v1/search*' => Http::response(spotifySearchResponse([
            ['id' => 'sp1', 'title' => 'One More Time', 'artist' => 'Daft Punk'],
            ['id' => 'sp2', 'title' => 'One More Time (Radio Edit)', 'artist' => 'Daft Punk'],
        ])),
        'www.googleapis.com/youtube/v3/search*' => Http::response(youtubeSearchResponse([])),
    ]);

    expect(($this->search)('one more time')->json('results'))->toHaveCount(2);
});

it('expose un objet SearchOutcome exploitable hors HTTP', function () {
    fakeBothProviders();

    $outcome = app(SearchService::class)->search($this->room, 'daft punk');

    expect($outcome)->toBeInstanceOf(SearchOutcome::class)
        ->and($outcome->results)->each->toBeInstanceOf(TrackData::class)
        ->and($outcome->sources)->toBe(['spotify' => 'ok', 'youtube' => 'ok']);
});
