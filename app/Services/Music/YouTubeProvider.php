<?php

namespace App\Services\Music;

use App\Data\ProviderRequest;
use App\Data\TrackData;
use App\Enums\Provider;
use App\Exceptions\ProviderException;
use DateInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Recherche de clips via YouTube Data API v3, en économisant le quota :
 *  - `search.list` coûte 100 unités (sur 10 000/jour) -> ~100 recherches par jour au maximum,
 *    d'où le cache Redis d'1 h (dans SearchService), le debounce et le minimum de 3 caractères côté client ;
 *  - `search.list` ne renvoie PAS les durées -> un seul `videos.list` groupé (1 unité) les récupère.
 *
 * Uniquement des métadonnées : la lecture se fait dans le lecteur IFrame officiel,
 * visible, sans extraction audio ni contournement des publicités.
 */
class YouTubeProvider implements MusicProvider
{
    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    /** Catégorie "Music" de YouTube. */
    private const MUSIC_CATEGORY_ID = '10';

    public function __construct(private readonly YouTubeQuota $quota) {}

    public function provider(): Provider
    {
        return Provider::YouTube;
    }

    public function isConfigured(): bool
    {
        return filled(config('services.youtube.key'));
    }

    public function searchRequest(string $query): ProviderRequest
    {
        $cost = YouTubeQuota::COST_SEARCH + YouTubeQuota::COST_VIDEOS;

        if (! $this->quota->canSpend($cost)) {
            throw new ProviderException('Quota YouTube du jour épuisé.');
        }

        // Dépense comptée dès la construction : SearchService envoie toujours la requête décrite ici,
        // et Google facture l'appel même s'il échoue.
        $this->quota->spend(YouTubeQuota::COST_SEARCH);

        return new ProviderRequest(
            url: self::API_URL.'/search',
            query: [
                'part' => 'snippet',
                'type' => 'video',
                'q' => $query,
                'maxResults' => (int) config('jukebox.search.per_provider'),
                // Écarte d'emblée les vidéos dont l'intégration est interdite (sinon erreur 101/150 à la lecture).
                'videoEmbeddable' => 'true',
                'videoCategoryId' => self::MUSIC_CATEGORY_ID,
                'key' => (string) config('services.youtube.key'),
            ],
        );
    }

    public function parseSearchResponse(Response $response): array
    {
        $this->guard($response, 'search');

        // id => snippet, en conservant l'ordre de pertinence renvoyé par YouTube.
        $snippets = [];

        foreach ($response->json('items', []) as $item) {
            $id = $item['id']['videoId'] ?? null;

            if (is_string($id) && $id !== '') {
                $snippets[$id] = $item['snippet'] ?? [];
            }
        }

        if ($snippets === []) {
            return [];
        }

        $durations = $this->durations(array_keys($snippets));
        $tracks = [];

        foreach ($snippets as $id => $snippet) {
            $durationMs = $durations[$id] ?? 0;

            // Durée nulle = direct en cours ou vidéo indisponible : impossible à gérer dans une file.
            if ($durationMs <= 0) {
                continue;
            }

            $tracks[] = $this->toTrackData((string) $id, $snippet, $durationMs);
        }

        return $tracks;
    }

    public function find(string $providerTrackId): ?TrackData
    {
        if (! $this->quota->canSpend(YouTubeQuota::COST_VIDEOS)) {
            throw new ProviderException('Quota YouTube du jour épuisé.');
        }

        $response = $this->videos([$providerTrackId], 'snippet,contentDetails,status');
        $item = $response->json('items.0');

        if (! is_array($item) || ($item['status']['embeddable'] ?? true) === false) {
            return null;
        }

        $durationMs = self::parseIsoDuration((string) ($item['contentDetails']['duration'] ?? ''));

        return $durationMs > 0 ? $this->toTrackData($providerTrackId, $item['snippet'] ?? [], $durationMs) : null;
    }

    /**
     * Convertit une durée ISO 8601 ("PT4M13S", "PT1H2M", "P0D" pour un direct) en millisecondes.
     * Renvoie 0 si la chaîne est vide ou invalide.
     */
    public static function parseIsoDuration(string $iso): int
    {
        if ($iso === '') {
            return 0;
        }

        try {
            $interval = new DateInterval($iso);
        } catch (Throwable) {
            return 0;
        }

        $seconds = $interval->d * 86_400 + $interval->h * 3_600 + $interval->i * 60 + $interval->s;

        return $seconds * 1_000;
    }

    /**
     * Durées de plusieurs vidéos en UN appel `videos.list` (1 unité, jusqu'à 50 ids).
     *
     * @param  list<string|int>  $ids
     * @return array<string, int> id => durée en ms
     */
    private function durations(array $ids): array
    {
        $response = $this->videos(array_map('strval', $ids), 'contentDetails');
        $durations = [];

        foreach ($response->json('items', []) as $item) {
            if (isset($item['id'])) {
                $durations[(string) $item['id']] = self::parseIsoDuration((string) ($item['contentDetails']['duration'] ?? ''));
            }
        }

        return $durations;
    }

    /** @param  list<string>  $ids */
    private function videos(array $ids, string $part): Response
    {
        $this->quota->spend(YouTubeQuota::COST_VIDEOS);

        try {
            $response = Http::timeout((int) config('jukebox.search.timeout'))
                ->get(self::API_URL.'/videos', [
                    'part' => $part,
                    'id' => implode(',', $ids),
                    'key' => (string) config('services.youtube.key'),
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderException('YouTube est injoignable.', previous: $e);
        }

        $this->guard($response, 'videos');

        return $response;
    }

    /** Lève une ProviderException si la réponse est une erreur ; mémorise l'épuisement du quota. */
    private function guard(Response $response, string $endpoint): void
    {
        if ($response->successful()) {
            return;
        }

        $reason = (string) $response->json('error.errors.0.reason', '');

        if ($response->status() === 403 && in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
            $this->quota->markExhausted();
        }

        throw new ProviderException("YouTube {$endpoint} a répondu HTTP {$response->status()} ({$reason}).");
    }

    /** @param  array<string, mixed>  $snippet */
    private function toTrackData(string $id, array $snippet, int $durationMs): TrackData
    {
        $thumbnails = $snippet['thumbnails'] ?? [];
        $thumbnail = $thumbnails['high']['url'] ?? $thumbnails['medium']['url'] ?? $thumbnails['default']['url'] ?? null;

        // search.list renvoie les titres encodés en entités HTML ("Rock &amp; Roll", "Don&#39;t").
        $decode = fn (string $text): string => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Les chaînes auto-générées s'appellent "Artiste - Topic".
        $channel = preg_replace('/\s+-\s+Topic$/', '', $decode((string) ($snippet['channelTitle'] ?? '')));

        return new TrackData(
            provider: Provider::YouTube,
            providerTrackId: $id,
            title: $decode((string) ($snippet['title'] ?? 'Sans titre')),
            artist: $channel !== '' && $channel !== null ? $channel : 'YouTube',
            albumArtUrl: $thumbnail,
            durationMs: $durationMs,
        );
    }
}
