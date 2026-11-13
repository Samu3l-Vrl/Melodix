<?php

namespace App\Data;

use App\Enums\Provider;

/**
 * Représentation normalisée d'un morceau renvoyé par une source (avant persistance).
 * C'est le format pivot entre les MusicProvider, le cache Redis et la table `tracks`.
 */
final readonly class TrackData
{
    public function __construct(
        public Provider $provider,
        public string $providerTrackId,
        public string $title,
        public string $artist,
        public ?string $albumArtUrl,
        public int $durationMs,
    ) {}

    /**
     * Forme sérialisable (cache Redis + réponse JSON de recherche).
     *
     * @return array{provider: string, provider_track_id: string, title: string, artist: string, album_art_url: string|null, duration_ms: int}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'provider_track_id' => $this->providerTrackId,
            'title' => $this->title,
            'artist' => $this->artist,
            'album_art_url' => $this->albumArtUrl,
            'duration_ms' => $this->durationMs,
        ];
    }

    /** @param array{provider: string, provider_track_id: string, title: string, artist: string, album_art_url: string|null, duration_ms: int} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: Provider::from($data['provider']),
            providerTrackId: $data['provider_track_id'],
            title: $data['title'],
            artist: $data['artist'],
            albumArtUrl: $data['album_art_url'],
            durationMs: $data['duration_ms'],
        );
    }
}
