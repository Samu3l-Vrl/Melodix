<?php

namespace Database\Factories;

use App\Enums\QueueItemStatus;
use App\Models\QueueItem;
use App\Models\Room;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique d'éléments de file. Dans les tests, préférer QueueService::add()
 * (qui attribue les positions sous verrou) ; cette fabrique sert aux cas isolés.
 *
 * @extends Factory<QueueItem>
 */
class QueueItemFactory extends Factory
{
    protected $model = QueueItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'track_id' => Track::factory(),
            'added_by_guest_id' => null,
            'added_by_user_id' => null,
            'position' => fake()->unique()->numberBetween(1, 1_000_000),
            'status' => QueueItemStatus::Queued,
            'played_at' => null,
        ];
    }
}
