<?php

namespace App\Services\Music;

use App\Data\PlaylistData;
use App\Data\ProviderRequest;
use App\Data\TrackData;
use App\Enums\Provider;
use App\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Recherche dans le catalogue Spotify avec le flux "client credentials" :
 * un jeton d'APPLICATION (pas celui de l'hôte), obtenu côté serveur et mis en cache.
 * Les invités peuvent ainsi chercher sans jamais toucher au jeton personnel de l'hôte.
 */
class SpotifyProvider implements MusicProvider
{
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    private const API_URL = 'https://api.spotify.com/v1';

    private const TOKEN_CACHE_KEY = 'spotify:app-token';

    public function provider(): Provider
    {
        return Provider::Spotify;
    }

    public function isConfigured(): bool
    {
        return filled(config('services.spotify.client_id')) && filled(config('services.spotify.client_secret'));
    }

    public function searchRequest(string $query): ProviderRequest
    {
        return new ProviderRequest(
            url: self::API_URL.'/search',
            query: [
                'q' => $query,
                'type' => 'track',
                'limit' => (int) config('jukebox.search.per_provider'),
                // Sans `market`, un jeton d'application renvoie des morceaux potentiellement
                // illisibles dans le pays de l'hôte.
                'market' => config('services.spotify.market'),
            ],
            bearerToken: $this->appToken(),
        );
    }

    public function parseSearchResponse(Response $response): array
    {
        if ($response->status() === 401) {
            // Jeton révoqué avant son expiration théorique : on l'oublie, la prochaine recherche en redemandera un.
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        if ($response->failed()) {
            throw new ProviderException("Spotify search a répondu HTTP {$response->status()}.");
        }

        $tracks = [];

        foreach ($response->json('tracks.items', []) as $item) {
            $track = $this->toTrackData($item);

            if ($track !== null) {
                $tracks[] = $track;
            }
        }

        return $tracks;
    }

    public function find(string $providerTrackId): ?TrackData
    {
        try {
            $response = Http::withToken($this->appToken())
                ->timeout((int) config('jukebox.search.timeout'))
                ->get(self::API_URL.'/tracks/'.rawurlencode($providerTrackId), [
                    'market' => config('services.spotify.market'),
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderException('Spotify est injoignable.', previous: $e);
        }

        if (in_array($response->status(), [400, 404], true)) {
            return null;
        }

        if ($response->failed()) {
            throw new ProviderException("Spotify tracks a répondu HTTP {$response->status()}.");
        }

        return $this->toTrackData($response->json());
    }

    /**
     * Playlists de l'utilisateur (les siennes et celles qu'il suit), pour le sélecteur de l'hôte.
     * Utilise le jeton PERSONNEL de l'hôte (scope playlist-read-private pour les playlists privées).
     *
     * @return list<array{id: string, name: string, image_url: string|null, track_count: int|null, owner: string|null}>
     */
    public function userPlaylists(string $userToken, int $limit = 50): array
    {
        $response = $this->userRequest($userToken)->get(self::API_URL.'/me/playlists', ['limit' => min(50, $limit)]);

        if ($response->failed()) {
            throw new ProviderException("Spotify me/playlists a répondu HTTP {$response->status()}.");
        }

        $playlists = [];

        foreach ($response->json('items', []) as $item) {
            if (! is_array($item) || empty($item['id'])) {
                continue;
            }

            // Le nombre de titres a été exposé sous « tracks.total » puis « items.total » selon les versions de l'API.
            $total = $item['tracks']['total'] ?? $item['items']['total'] ?? null;

            $playlists[] = [
                'id' => (string) $item['id'],
                'name' => (string) ($item['name'] ?? 'Sans titre'),
                'image_url' => $item['images'][0]['url'] ?? null,
                'track_count' => is_numeric($total) ? (int) $total : null,
                'owner' => $item['owner']['display_name'] ?? null,
            ];
        }

        return $playlists;
    }

    /**
     * Lit une playlist complète (jusqu'à $maxTracks morceaux) avec le jeton personnel de l'hôte.
     * Null si Spotify ne la trouve pas ou n'y donne pas accès.
     *
     * Attention : depuis fin 2024, Spotify refuse aux nouvelles applications l'accès aux playlists
     * éditoriales et générées (« Daily Mix », « Top 50 »…) : elles répondent 404 comme une playlist inexistante.
     */
    public function playlist(string $playlistId, string $userToken, int $maxTracks): ?PlaylistData
    {
        $http = $this->userRequest($userToken);
        $meta = $http->get(self::API_URL.'/playlists/'.rawurlencode($playlistId), [
            'market' => config('services.spotify.market'),
            'fields' => 'id,name,images',
        ]);

        if (in_array($meta->status(), [403, 404], true)) {
            return null;
        }

        if ($meta->failed()) {
            throw new ProviderException("Spotify playlists a répondu HTTP {$meta->status()}.");
        }

        $tracks = [];
        $url = self::API_URL.'/playlists/'.rawurlencode($playlistId).'/tracks';
        $query = ['market' => config('services.spotify.market'), 'limit' => 100, 'offset' => 0];

        // Pagination : 100 morceaux par page, jusqu'à $maxTracks.
        while (count($tracks) < $maxTracks) {
            $page = $http->get($url, $query);

            if ($page->failed()) {
                throw new ProviderException("Spotify playlist tracks a répondu HTTP {$page->status()}.");
            }

            $items = $page->json('items', []);

            foreach ($items as $entry) {
                // Les entrées peuvent être des épisodes de podcast ou des fichiers locaux : seuls les morceaux comptent.
                $item = $entry['track'] ?? $entry['item'] ?? null;

                if (is_array($item) && ($item['type'] ?? 'track') === 'track' && ! ($item['is_local'] ?? false)) {
                    $track = $this->toTrackData($item);

                    if ($track !== null && $track->durationMs > 0) {
                        $tracks[] = $track;
                    }
                }
            }

            if ($page->json('next') === null || $items === []) {
                break;
            }

            $query['offset'] += 100;
        }

        return new PlaylistData(
            provider: Provider::Spotify,
            providerPlaylistId: $playlistId,
            name: (string) ($meta->json('name') ?? 'Playlist Spotify'),
            imageUrl: $meta->json('images.0.url'),
            tracks: array_slice($tracks, 0, $maxTracks),
        );
    }

    private function userRequest(string $userToken): PendingRequest
    {
        return Http::withToken($userToken)->timeout((int) config('jukebox.search.timeout'));
    }

    /**
     * Jeton d'application (client credentials), partagé par toutes les recherches.
     * Mis en cache jusqu'à 60 s avant son expiration pour ne jamais servir un jeton mourant.
     */
    private function appToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth((string) config('services.spotify.client_id'), (string) config('services.spotify.client_secret'))
                ->timeout((int) config('jukebox.search.timeout'))
                ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);
        } catch (ConnectionException $e) {
            throw new ProviderException('Spotify (jeton) est injoignable.', previous: $e);
        }

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token)) {
            throw new ProviderException("Impossible d'obtenir un jeton d'application Spotify (HTTP {$response->status()}).");
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 60);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /**
     * Normalise un objet "track" de l'API Spotify. Null si l'objet est inutilisable.
     *
     * @param  array<string, mixed>|null  $item
     */
    public function toTrackData(?array $item): ?TrackData
    {
        // `is_playable: false` (avec market) = morceau indisponible dans le pays : inutile de le proposer.
        if (! is_array($item) || empty($item['id']) || ($item['is_playable'] ?? true) === false) {
            return null;
        }

        $artists = collect($item['artists'] ?? [])->pluck('name')->filter()->implode(', ');
        $images = $item['album']['images'] ?? [];

        return new TrackData(
            provider: Provider::Spotify,
            providerTrackId: (string) $item['id'],
            title: (string) ($item['name'] ?? 'Sans titre'),
            artist: $artists !== '' ? $artists : 'Artiste inconnu',
            // Les images sont triées de la plus grande à la plus petite : la première convient à l'écran TV.
            albumArtUrl: $images[0]['url'] ?? null,
            durationMs: (int) ($item['duration_ms'] ?? 0),
        );
    }
}
