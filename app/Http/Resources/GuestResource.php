<?php

namespace App\Http\Resources;

use App\Models\Guest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialisation publique d'un invité (jamais son jeton). Aligné sur le type TS `Guest`.
 *
 * @mixin Guest
 */
class GuestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
        ];
    }
}
