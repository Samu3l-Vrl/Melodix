<?php

namespace App\Services\Music;

use App\Data\SearchOutcome;
use App\Data\TrackData;
use App\Models\Room;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recherche unifiée multi-source.
 *
 * Déroulé : 1) lecture du cache par source, 2) requêtes manquantes envoyées EN PARALLÈLE
 * (Http::pool, timeout 4 s), 3) dégradation gracieuse — une source en échec n'empêche jamais
 * l'autre de répondre —, 4) entrelacement des résultats et dédoublonnage.
 */
class SearchService
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    public function search(Room $room, string $query): SearchOutcome
    {
        $query = self::normalizeQuery($query);

        /** @var array<string, list<TrackData>> $results provider => morceaux */
        $results = [];
        /** @var array<string, string> $sources provider => ok | error | disabled */
        $sources = [];
        /** @var array<string, \App\Data\ProviderRequest> $requests requêtes à envoyer dans le pool */
        $requests = [];
        /** @var array<string, MusicProvider> $pending sources dont on attend une réponse HTTP */
        $pending = [];

        foreach ($this->registry->forRoom($room) as $key => ['provider' => $provider, 'enabled' => $enabled]) {
            if (! $enabled) {
                $sources[$key] = SearchOutcome::SOURCE_DISABLED;

                continue;
            }

            $cached = Cache::get($this->searchCacheKey($key, $query));

            if (is_array($cached)) {
                $results[$key] = array_map(TrackData::fromArray(...), $cached);
                $sources[$key] = SearchOutcome::SOURCE_OK;

                continue;
            }

            // Réserve la place de la source dans la réponse (ordre stable : celui du registre).
            // Valeur pessimiste, remplacée par "ok" si la source répond correctement.
            $sources[$key] = SearchOutcome::SOURCE_ERROR;

            try {
                // Peut échouer AVANT tout appel réseau de recherche (jeton Spotify, quota YouTube).
                $requests[$key] = $provider->searchRequest($query);
                $pending[$key] = $provider;
            } catch (Throwable $e) {
                $sources[$key] = $this->failed($key, $e);
            }
        }

        if ($requests !== []) {
            $timeout = (int) config('jukebox.search.timeout');

            // Toutes les sources partent en même temps : la latence totale est celle de la plus lente
            // (bornée par le timeout), pas la somme des deux.
            $responses = Http::pool(function (Pool $pool) use ($requests, $timeout) {
                $promises = [];

                foreach ($requests as $key => $request) {
                    // `as($key)` nomme la réponse : on la retrouvera dans $responses[$key].
                    $http = $pool->as($key)->timeout($timeout)->connectTimeout($timeout);

                    if ($request->bearerToken !== null) {
                        $http = $http->withToken($request->bearerToken);
                    }

                    $promises[] = $http->get($request->url, $request->query);
                }

                return $promises;
            });

            foreach ($pending as $key => $provider) {
                $response = $responses[$key] ?? null;

                try {
                    // Dans un pool, un échec réseau (timeout, DNS…) n'est pas levé : il est RENVOYÉ à la place de la réponse.
                    if ($response instanceof Throwable) {
                        throw $response;
                    }

                    if (! $response instanceof Response) {
                        throw new \RuntimeException('Réponse absente du pool.');
                    }

                    $tracks = $provider->parseSearchResponse($response);

                    $this->remember($key, $query, $tracks);
                    $results[$key] = $tracks;
                    $sources[$key] = SearchOutcome::SOURCE_OK;
                } catch (Throwable $e) {
                    $sources[$key] = $this->failed($key, $e);
                }
            }
        }

        return new SearchOutcome(
            results: $this->merge($results, (int) config('jukebox.search.max_results')),
            sources: $sources,
        );
    }

    /**
     * Clé de cache d'un morceau individuel : permet à l'ajout en file de retrouver les
     * métadonnées vues à la recherche sans rappeler la source (et sans croire le client).
     */
    public static function trackCacheKey(string $provider, string $providerTrackId): string
    {
        return "track:{$provider}:{$providerTrackId}";
    }

    /** "  Daft   PUNK " et "daft punk" doivent partager la même entrée de cache. */
    public static function normalizeQuery(string $query): string
    {
        return Str::lower(Str::squish($query));
    }

    /**
     * Entrelace les listes (1er Spotify, 1er YouTube, 2e Spotify, …) en écartant les doublons.
     * Chaque source classe déjà par pertinence : alterner garde le meilleur de chacune en haut de liste.
     *
     * @param  array<string, list<TrackData>>  $results
     * @return list<TrackData>
     */
    private function merge(array $results, int $limit): array
    {
        $merged = [];
        /** @var array<string, true> $seenIds */
        $seenIds = [];
        /** @var array<string, string> $seenSongs empreinte => première source à l'avoir proposée */
        $seenSongs = [];
        $lists = array_values(array_filter($results));
        $longest = $lists === [] ? 0 : max(array_map('count', $lists));

        for ($rank = 0; $rank < $longest; $rank++) {
            foreach ($lists as $list) {
                if (! isset($list[$rank])) {
                    continue;
                }

                $track = $list[$rank];
                $source = $track->provider->value;
                $idKey = $source.':'.$track->providerTrackId;
                $songKey = self::fingerprint($track);

                // Même identifiant déjà retenu, ou même chanson déjà retenue chez une AUTRE source.
                // (Au sein d'une même source on garde les variantes : "Radio Edit", "Live", remix…)
                if (isset($seenIds[$idKey]) || (isset($seenSongs[$songKey]) && $seenSongs[$songKey] !== $source)) {
                    continue;
                }

                $seenIds[$idKey] = true;
                $seenSongs[$songKey] ??= $source;
                $merged[] = $track;

                if (count($merged) >= $limit) {
                    return $merged;
                }
            }
        }

        return $merged;
    }

    /**
     * Empreinte "artiste + titre" tolérante, pour reconnaître la même chanson sur deux sources.
     * Heuristique assumée : Spotify donne ("One More Time", "Daft Punk"), YouTube donne
     * ("Daft Punk - One More Time (Official Video)", "Daft Punk"). On retire les parenthèses,
     * les mentions "official video/audio/lyrics…" et la ponctuation, puis on compare les mots triés.
     */
    public static function fingerprint(TrackData $track): string
    {
        $text = Str::lower(Str::ascii($track->artist.' '.$track->title));
        $text = (string) preg_replace('/[\(\[][^\)\]]*[\)\]]/', ' ', $text);
        $text = (string) preg_replace('/\b(official|officiel|video|audio|lyrics?|clip|music|hd|hq|4k|remaster(ed)?|feat|ft|vevo|topic)\b/', ' ', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        // Mots uniques triés : "daft punk daft punk one more time" == "daft punk one more time".
        $words = array_unique(array_filter(explode(' ', $text)));
        sort($words);

        return 'song:'.implode(' ', $words);
    }

    /**
     * Met en cache la liste (clé par source + requête) et chaque morceau (clé par identifiant).
     *
     * @param  list<TrackData>  $tracks
     */
    private function remember(string $provider, string $query, array $tracks): void
    {
        $ttl = (int) config('jukebox.search.cache_ttl');

        Cache::put($this->searchCacheKey($provider, $query), array_map(fn (TrackData $t) => $t->toArray(), $tracks), $ttl);

        foreach ($tracks as $track) {
            // TTL doublé : un invité peut ajouter un morceau longtemps après l'avoir cherché.
            Cache::put(self::trackCacheKey($provider, $track->providerTrackId), $track->toArray(), $ttl * 2);
        }
    }

    private function searchCacheKey(string $provider, string $query): string
    {
        // sha1 : clé de longueur fixe, sans caractères problématiques, quelle que soit la saisie.
        return "search:v1:{$provider}:".sha1($query);
    }

    /** Journalise l'échec d'une source et renvoie son statut. Jamais d'exception vers l'appelant. */
    private function failed(string $provider, Throwable $e): string
    {
        Log::warning("Recherche {$provider} en échec : {$e->getMessage()}");

        return SearchOutcome::SOURCE_ERROR;
    }
}
