<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de morceaux (YouTube par défaut : jouable quel que soit l'abonnement de l'hôte).
 *
 * @extends Factory<Track>
 */
class TrackFactory extends Factory
{
    protected $model = Track::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => Provider::YouTube,
            'provider_track_id' => fake()->unique()->regexify('[A-Za-z0-9_-]{11}'),
            'title' => fake()->sentence(3),
            'artist' => fake()->name(),
            'album_art_url' => 'https://img.example.test/'.fake()->uuid().'.jpg',
            'duration_ms' => fake()->numberBetween(120_000, 300_000),
        ];
    }

    public function spotify(): static
    {
        return $this->state(fn () => [
            'provider' => Provider::Spotify,
            'provider_track_id' => fake()->unique()->regexify('[A-Za-z0-9]{22}'),
        ]);
    }
}
