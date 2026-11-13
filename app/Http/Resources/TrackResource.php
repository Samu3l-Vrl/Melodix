<?php

namespace App\Http\Resources;

use App\Models\Track;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialisation d'un morceau persistant. Aligné sur le type TS `Track`.
 *
 * @mixin Track
 */
class TrackResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'provider_track_id' => $this->provider_track_id,
            'title' => $this->title,
            'artist' => $this->artist,
            'album_art_url' => $this->album_art_url,
            'duration_ms' => $this->duration_ms,
        ];
    }
}
