<?php

namespace App\Http\Resources;

use App\Enums\Provider;
use App\Models\Room;
use App\Services\Music\ProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * État COMPLET d'un salon : c'est la réponse de GET /api/rooms/{code}/state, la prop
 * Inertia initiale et la réponse de toutes les actions. Aligné sur le type TS `RoomState`.
 *
 * @mixin Room
 */
class RoomStateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status->value,
            'version' => $this->version,
            // Horloge serveur (ms epoch) : permet au client de corriger le décalage de sa propre horloge.
            'server_time' => now()->getTimestampMs(),
            'spotify_enabled' => app(ProviderRegistry::class)->isEnabledFor(Provider::Spotify, $this->resource),
            'playback' => PlaybackStateResource::plain($this->resource),
            'queue' => $this->queue
                ->map(fn ($item) => QueueItemResource::plain($item))
                ->values()
                ->all(),
        ];
    }

    /**
     * Point d'entrée unique : recharge les relations puis sérialise.
     * Évite d'oublier un `load()` (N+1) ou de sérialiser des relations périmées.
     */
    public static function for(Room $room): self
    {
        return new self($room->loadState());
    }

    /** @return array<string, mixed> */
    public static function plain(Room $room): array
    {
        return self::for($room)->resolve(request());
    }
}
