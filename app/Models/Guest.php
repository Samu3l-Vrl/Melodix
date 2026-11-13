<?php

namespace App\Models;

use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Invité d'un salon : un pseudo et un jeton opaque, valable pour ce salon uniquement.
 *
 * @property int $id
 * @property int $room_id
 * @property string $nickname
 * @property string $token
 * @property Carbon|null $last_seen_at
 */
class Guest extends Model
{
    /** @use HasFactory<GuestFactory> */
    use HasFactory;

    public const TOKEN_LENGTH = 40;

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'nickname',
        'token',
        'last_seen_at',
    ];

    /**
     * Le jeton n'est renvoyé qu'une seule fois, explicitement, à la création.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /** Jeton aléatoire de 40 caractères (≈ 238 bits d'entropie). */
    public static function generateToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
