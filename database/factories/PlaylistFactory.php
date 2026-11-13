<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Models\Playlist;
use App\Models\Room;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Fabrique de playlists de fond (YouTube par défaut).
 *
 * @extends Factory<Playlist>
 */
class PlaylistFactory extends Factory
{
    protected $model = Playlist::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'provider' => Provider::YouTube,
            'provider_playlist_id' => 'PL'.fake()->regexify('[A-Za-z0-9_-]{16}'),
            'name' => 'Playlist '.fake()->word(),
            'image_url' => null,
            'shuffled' => false,
        ];
    }

    /** Ajoute $count morceaux (ou les morceaux fournis), positions 0, 1, 2… */
    public function withTracks(int $count = 3): static
    {
        return $this->afterCreating(function (Playlist $playlist) use ($count) {
            foreach (Track::factory()->count($count)->create() as $position => $track) {
                DB::table('playlist_tracks')->insert([
                    'playlist_id' => $playlist->id,
                    'track_id' => $track->id,
                    'position' => $position,
                ]);
            }
        });
    }
}
