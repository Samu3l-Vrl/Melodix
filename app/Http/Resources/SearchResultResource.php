<?php

namespace App\Http\Resources;

use App\Data\TrackData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialisation d'un résultat de recherche (morceau non encore persistant, donc sans id).
 * Aligné sur le type TS `SearchResult`.
 *
 * @property-read TrackData $resource
 */
class SearchResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
