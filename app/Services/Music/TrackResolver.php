<?php

namespace App\Services\Music;

use App\Data\TrackData;
use App\Enums\Provider;
use App\Exceptions\ProviderException;
use App\Exceptions\TrackUnavailableException;
use App\Models\Track;
use Illuminate\Support\Facades\Cache;

/**
 * Transforme un couple (source, identifiant) envoyé par un invité en ligne `tracks`.
 *
 * Le client n'envoie QUE l'identifiant : titre, artiste, pochette et durée viennent
 * toujours du serveur (base, cache de recherche ou API de la source). Un invité ne peut
 * donc pas afficher un faux titre sur l'écran de la soirée en forgeant sa requête.
 */
class TrackResolver
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /** @throws TrackUnavailableException Si le morceau est introuvable ou la source en panne. */
    public function resolve(Provider $provider, string $providerTrackId): Track
    {
        // 1) Déjà connu : aucun appel externe.
        $existing = Track::query()
            ->where('provider', $provider)
            ->where('provider_track_id', $providerTrackId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // 2) Vu récemment dans une recherche, sinon 3) question directe à la source.
        $data = $this->fromCache($provider, $providerTrackId) ?? $this->fromProvider($provider, $providerTrackId);

        if ($data === null) {
            throw new TrackUnavailableException;
        }

        // createOrFirst : si deux invités ajoutent le même morceau inconnu au même instant,
        // l'index unique (provider, provider_track_id) fait échouer le second INSERT,
        // qui relit alors la ligne créée par le premier au lieu de planter.
        return Track::query()->createOrFirst(
            ['provider' => $provider, 'provider_track_id' => $providerTrackId],
            [
                'title' => $data->title,
                'artist' => $data->artist,
                'album_art_url' => $data->albumArtUrl,
                'duration_ms' => $data->durationMs,
            ],
        );
    }

    private function fromCache(Provider $provider, string $providerTrackId): ?TrackData
    {
        $cached = Cache::get(SearchService::trackCacheKey($provider->value, $providerTrackId));

        return is_array($cached) ? TrackData::fromArray($cached) : null;
    }

    private function fromProvider(Provider $provider, string $providerTrackId): ?TrackData
    {
        try {
            return $this->registry->get($provider)->find($providerTrackId);
        } catch (ProviderException $e) {
            report($e);

            throw new TrackUnavailableException('La source est momentanément indisponible. Réessayez.');
        }
    }
}
