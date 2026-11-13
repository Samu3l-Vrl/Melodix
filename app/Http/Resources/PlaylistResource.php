<?php

namespace App\Http\Resources;

use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Résumé de la playlist de fond. Aligné sur le type TS `PlaylistSummary`.
 *
 * @mixin Playlist
 */
class PlaylistResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'name' => $this->name,
            'image_url' => $this->image_url,
            'shuffled' => $this->shuffled,
        ];
    }
}
