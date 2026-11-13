<?php

namespace App\Models;

use App\Enums\QueueItemSource;
use App\Enums\QueueItemStatus;
use Database\Factories\QueueItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Élément de la file d'un salon : un morceau, un auteur (invité ou hôte), une position.
 *
 * @property int $id
 * @property int $room_id
 * @property int $track_id
 * @property int|null $added_by_guest_id
 * @property int|null $added_by_user_id
 * @property QueueItemSource $source
 * @property int|null $playlist_id
 * @property int $position
 * @property QueueItemStatus $status
 * @property Carbon|null $played_at
 * @property-read Track $track
 * @property-read Guest|null $guest
 * @property-read User|null $user
 * @property-read Playlist|null $playlist
 */
class QueueItem extends Model
{
    /** @use HasFactory<QueueItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'track_id',
        'added_by_guest_id',
        'added_by_user_id',
        'source',
        'playlist_id',
        'position',
        'status',
        'played_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued',
        'source' => 'guest',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => QueueItemStatus::class,
            'source' => QueueItemSource::class,
            'played_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'added_by_guest_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /** @return BelongsTo<Playlist, $this> */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
