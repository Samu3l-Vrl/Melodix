<?php

namespace App\Events;

use App\Models\Guest;
use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Diffusé sur le canal PRIVÉ `private-room.{code}.host` quand un invité rejoint le salon.
 * Information réservée à l'hôte (notification "Léa a rejoint") : elle ne fait pas partie
 * de l'état versionné, d'où l'absence de `version`.
 */
class GuestJoined implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $code,
        public readonly int $guestId,
        public readonly string $nickname,
        public readonly int $guestsCount,
    ) {}

    public static function fromGuest(Room $room, Guest $guest): self
    {
        return new self($room->code, $guest->id, $guest->nickname, $room->guests()->count());
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        // PrivateChannel préfixe automatiquement le nom par "private-".
        return [new PrivateChannel('room.'.$this->code.'.host')];
    }

    public function broadcastAs(): string
    {
        return 'guest.joined';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'guest' => ['id' => $this->guestId, 'nickname' => $this->nickname],
            'guests_count' => $this->guestsCount,
        ];
    }
}
