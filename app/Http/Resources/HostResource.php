<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialisation de l'hôte connecté (prop Inertia partagée `auth.user`).
 * Aligné sur le type TS `HostUser`. Aucun jeton Spotify ici.
 *
 * @mixin User
 */
class HostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar' => $this->avatar,
            'spotify_premium' => $this->hasSpotifyPremium(),
        ];
    }
}
