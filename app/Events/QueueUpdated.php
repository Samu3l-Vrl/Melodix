<?php

namespace App\Events;

use App\Http\Resources\QueueItemResource;
use App\Models\QueueItem;
use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Diffusé sur le canal public `room.{code}` à chaque mutation de la file
 * (ajout, retrait, réordonnancement). Le payload est un DELTA : le client
 * l'applique seulement si `version` vaut exactement sa version locale + 1.
 */
class QueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public const ACTION_ADDED = 'added';

    public const ACTION_REMOVED = 'removed';

    public const ACTION_REORDERED = 'reordered';

    /** Horloge serveur (ms epoch) figée à la création de l'event. */
    public readonly int $serverTime;

    /**
     * @param  array<string, mixed>|null  $item  Élément ajouté (action "added").
     * @param  int|null  $itemId  Élément retiré (action "removed").
     * @param  list<int>|null  $order  Nouvel ordre complet des ids en attente (action "reordered").
     */
    private function __construct(
        public readonly string $code,
        public readonly int $version,
        public readonly string $action,
        public readonly ?array $item = null,
        public readonly ?int $itemId = null,
        public readonly ?array $order = null,
    ) {
        $this->serverTime = now()->getTimestampMs();
    }

    public static function added(Room $room, QueueItem $item): self
    {
        return new self($room->code, $room->version, self::ACTION_ADDED, item: QueueItemResource::plain($item));
    }

    public static function removed(Room $room, int $itemId): self
    {
        return new self($room->code, $room->version, self::ACTION_REMOVED, itemId: $itemId);
    }

    /** @param  list<int>  $order */
    public static function reordered(Room $room, array $order): self
    {
        return new self($room->code, $room->version, self::ACTION_REORDERED, order: $order);
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('room.'.$this->code)];
    }

    /** Nom court côté client : `.listen('.queue.updated')`. */
    public function broadcastAs(): string
    {
        return 'queue.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'version' => $this->version,
            'server_time' => $this->serverTime,
            'action' => $this->action,
            'item' => $this->item,
            'item_id' => $this->itemId,
            'order' => $this->order,
        ];
    }
}
