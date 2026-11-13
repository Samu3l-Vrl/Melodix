<?php

namespace App\Events;

use App\Http\Resources\PlaybackStateResource;
use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Diffusé sur le canal public `room.{code}` à chaque changement de lecture :
 * play, pause, seek, passage au morceau suivant, arrêt.
 * Quand le morceau courant change, le client retire lui-même ce morceau de sa file locale.
 */
class PlaybackStateChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    /** Horloge serveur (ms epoch) figée à la création de l'event. */
    public readonly int $serverTime;

    /** @param  array<string, mixed>  $playback  Voir PlaybackStateResource. */
    private function __construct(
        public readonly string $code,
        public readonly int $version,
        public readonly array $playback,
    ) {
        $this->serverTime = now()->getTimestampMs();
    }

    public static function fromRoom(Room $room): self
    {
        return new self($room->code, $room->version, PlaybackStateResource::plain($room));
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('room.'.$this->code)];
    }

    /** Nom court côté client : `.listen('.playback.changed')`. */
    public function broadcastAs(): string
    {
        return 'playback.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'version' => $this->version,
            'server_time' => $this->serverTime,
            'playback' => $this->playback,
        ];
    }
}
