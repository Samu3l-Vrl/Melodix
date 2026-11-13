<?php

namespace App\Data;

use App\Enums\Provider;

/**
 * Playlist lue chez une source, avant enregistrement en base.
 */
final readonly class PlaylistData
{
    /**
     * @param  list<TrackData>  $tracks  Morceaux lisibles, dans l'ordre de la playlist.
     */
    public function __construct(
        public Provider $provider,
        public string $providerPlaylistId,
        public string $name,
        public ?string $imageUrl,
        public array $tracks,
    ) {}
}
