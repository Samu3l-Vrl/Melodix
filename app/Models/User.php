<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Hôte d'un salon, authentifié exclusivement via OAuth Spotify.
 *
 * @property int $id
 * @property string $spotify_id
 * @property string $name
 * @property string|null $avatar
 * @property string|null $spotify_product
 * @property string|null $spotify_access_token
 * @property string|null $spotify_refresh_token
 * @property Carbon|null $spotify_expires_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'spotify_id',
        'name',
        'avatar',
        'spotify_product',
        'spotify_access_token',
        'spotify_refresh_token',
        'spotify_expires_at',
    ];

    /**
     * Les jetons ne doivent jamais partir dans une réponse JSON ou une prop Inertia.
     *
     * @var list<string>
     */
    protected $hidden = [
        'spotify_access_token',
        'spotify_refresh_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Chiffrés au repos avec APP_KEY : une fuite de la base ne donne pas accès aux comptes Spotify.
            'spotify_access_token' => 'encrypted',
            'spotify_refresh_token' => 'encrypted',
            'spotify_expires_at' => 'datetime',
        ];
    }

    /**
     * Pas de "se souvenir de moi" : la table n'a pas de colonne remember_token.
     * Renvoyer un nom vide indique à Laravel de ne jamais lire ni écrire ce jeton.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'host_user_id');
    }

    /** Le Web Playback SDK ne fonctionne qu'avec un compte Premium. */
    public function hasSpotifyPremium(): bool
    {
        return $this->spotify_product === 'premium';
    }
}
