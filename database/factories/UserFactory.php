<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique d'hôtes : Premium par défaut avec un jeton valide une heure.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'spotify_id' => fake()->unique()->regexify('[a-z0-9]{24}'),
            'name' => fake()->name(),
            'avatar' => null,
            'spotify_product' => 'premium',
            'spotify_access_token' => 'access-'.fake()->sha1(),
            'spotify_refresh_token' => 'refresh-'.fake()->sha1(),
            'spotify_expires_at' => now()->addHour(),
        ];
    }

    /** Hôte sans abonnement Premium : la lecture Spotify doit être désactivée. */
    public function free(): static
    {
        return $this->state(['spotify_product' => 'free']);
    }

    /** Jeton d'accès sur le point d'expirer : force un rafraîchissement. */
    public function expiringToken(): static
    {
        return $this->state(['spotify_expires_at' => now()->addSeconds(30)]);
    }
}
