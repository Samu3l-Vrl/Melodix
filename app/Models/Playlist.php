<?php

namespace App\Models;

use App\Enums\Provider;
use Database\Factories\PlaylistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Playlist de fond d'un salon, importée depuis Spotify ou YouTube.
 * Les morceaux sont copiés en base à l'import : la lecture ne rappelle jamais la source.
 *
 * @property int $id
 * @property int $room_id
 * @property Provider $provider
 * @property string $provider_playlist_id
 * @property string $name
 * @property string|null $image_url
 * @property bool $shuffled
 */
class Playlist extends Model
{
    /** @use HasFactory<PlaylistFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'provider',
        'provider_playlist_id',
        'name',
        'image_url',
        'shuffled',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'shuffled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Morceaux dans l'ordre de lecture.
     *
     * @return BelongsToMany<Track, $this>
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'playlist_tracks')
            ->withPivot('position')
            ->orderByPivot('position');
    }
}
