<?php

namespace Database\Factories;

use App\Enums\PlaybackStatus;
use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomCodeGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de salons ouverts, à l'arrêt, avec un code valide et unique.
 *
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // On passe par le vrai générateur pour respecter l'alphabet et l'unicité.
            'code' => fn () => app(RoomCodeGenerator::class)->generate(),
            'host_user_id' => User::factory(),
            'status' => RoomStatus::Open,
            'playback_status' => PlaybackStatus::Idle,
            'current_queue_item_id' => null,
            'position_ms' => 0,
            'position_updated_at' => null,
            'version' => 0,
            'last_activity_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(['status' => RoomStatus::Closed]);
    }

    /** Salon sans activité depuis $hours heures (pour tester rooms:close-inactive). */
    public function inactiveFor(int $hours): static
    {
        return $this->state(['last_activity_at' => now()->subHours($hours)]);
    }
}
