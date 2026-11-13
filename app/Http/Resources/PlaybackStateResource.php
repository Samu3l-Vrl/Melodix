<?php

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * État de lecture d'un salon. Aligné sur le type TS `PlaybackState`.
 * Utilisé à la fois dans l'état complet et dans l'event PlaybackStateChanged.
 *
 * @mixin Room
 */
class PlaybackStateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->playback_status->value,
            'current' => $this->currentItem !== null
                ? QueueItemResource::plain($this->currentItem)
                : null,
            // Point de référence : "à l'instant position_updated_at, la lecture était à position_ms".
            // Le client extrapole lui-même (voir resources/js/lib/time.ts).
            'position_ms' => $this->position_ms,
            'position_updated_at' => $this->position_updated_at?->getTimestampMs(),
        ];
    }

    /** @return array<string, mixed> */
    public static function plain(Room $room): array
    {
        $room->loadMissing(['currentItem.track', 'currentItem.guest', 'currentItem.user']);

        return (new self($room))->resolve(request());
    }
}
