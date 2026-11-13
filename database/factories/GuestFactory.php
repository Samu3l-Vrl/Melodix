<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique d'invités rattachés à un salon.
 *
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    protected $model = Guest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'nickname' => fake()->firstName(),
            'token' => Guest::generateToken(),
            'last_seen_at' => now(),
        ];
    }
}
