<?php

namespace App\Services\Music;

use App\Enums\Provider;
use App\Models\Room;
use InvalidArgumentException;

/**
 * Annuaire des sources musicales. Seul endroit qui sait quelles sources existent
 * et lesquelles sont utilisables dans un salon donné.
 */
class ProviderRegistry
{
    /** @var array<string, MusicProvider> indexé par Provider::value */
    private array $providers = [];

    /** @param  iterable<MusicProvider>  $providers  L'ordre fixe la priorité à l'entrelacement des résultats. */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->provider()->value] = $provider;
        }
    }

    public function get(Provider $provider): MusicProvider
    {
        return $this->providers[$provider->value]
            ?? throw new InvalidArgumentException("Source inconnue : {$provider->value}");
    }

    /**
     * Une source est utilisable dans un salon si elle est configurée ET lisible par l'hôte.
     * Spotify exige un hôte Premium (contrainte du Web Playback SDK) : inutile de proposer
     * aux invités des morceaux que l'écran hôte ne pourra pas jouer.
     */
    public function isEnabledFor(Provider $provider, Room $room): bool
    {
        if (! isset($this->providers[$provider->value]) || ! $this->providers[$provider->value]->isConfigured()) {
            return false;
        }

        return match ($provider) {
            Provider::Spotify => $room->host->hasSpotifyPremium(),
            Provider::YouTube => true,
        };
    }

    /**
     * Toutes les sources connues, avec leur disponibilité pour ce salon.
     *
     * @return array<string, array{provider: MusicProvider, enabled: bool}>
     */
    public function forRoom(Room $room): array
    {
        $result = [];

        foreach ($this->providers as $key => $provider) {
            $result[$key] = [
                'provider' => $provider,
                'enabled' => $this->isEnabledFor($provider->provider(), $room),
            ];
        }

        return $result;
    }
}
