<?php

namespace App\Models;

use App\Casts\AsPreciseDateTime;
use App\Enums\PlaybackStatus;
use App\Enums\QueueItemStatus;
use App\Enums\RoomStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Salon de jukebox. Porte l'état de lecture (source de vérité) et le compteur `version`.
 *
 * @property int $id
 * @property string $code
 * @property int $host_user_id
 * @property RoomStatus $status
 * @property PlaybackStatus $playback_status
 * @property int|null $current_queue_item_id
 * @property int|null $playlist_id
 * @property int $playlist_position
 * @property int $transition_seconds
 * @property int $position_ms
 * @property CarbonImmutable|null $position_updated_at
 * @property int $version
 * @property Carbon|null $last_activity_at
 * @property-read User $host
 * @property-read QueueItem|null $currentItem
 * @property-read Playlist|null $playlist
 */
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /** Transition par défaut entre deux morceaux (secondes). Doit correspondre au défaut de la migration. */
    public const DEFAULT_TRANSITION_SECONDS = 6;

    /** Durées proposées à l'hôte (0 = pas de transition). */
    public const TRANSITION_CHOICES = [0, 3, 6, 10];

    /** @var list<string> */
    protected $fillable = [
        'code',
        'host_user_id',
        'status',
        'playback_status',
        'current_queue_item_id',
        'playlist_id',
        'playlist_position',
        'transition_seconds',
        'position_ms',
        'position_updated_at',
        'version',
        'last_activity_at',
    ];

    /**
     * Valeurs par défaut côté modèle : un salon fraîchement instancié est cohérent
     * sans devoir être relu depuis la base.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'playback_status' => 'idle',
        'position_ms' => 0,
        'playlist_position' => 0,
        'transition_seconds' => self::DEFAULT_TRANSITION_SECONDS,
        'version' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'playback_status' => PlaybackStatus::class,
            'current_queue_item_id' => 'integer',
            'playlist_id' => 'integer',
            'playlist_position' => 'integer',
            'transition_seconds' => 'integer',
            'position_ms' => 'integer',
            'position_updated_at' => AsPreciseDateTime::class,
            'version' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    /** Les URLs utilisent le code à 4 lettres, jamais l'id interne. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /** @return HasMany<Guest, $this> */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    /** @return HasMany<QueueItem, $this> */
    public function queueItems(): HasMany
    {
        return $this->hasMany(QueueItem::class);
    }

    /**
     * File d'attente visible : uniquement les éléments en attente, dans l'ordre FIFO.
     *
     * @return HasMany<QueueItem, $this>
     */
    public function queue(): HasMany
    {
        return $this->queueItems()
            ->where('status', QueueItemStatus::Queued)
            ->orderBy('position');
    }

    /** @return BelongsTo<QueueItem, $this> */
    public function currentItem(): BelongsTo
    {
        return $this->belongsTo(QueueItem::class, 'current_queue_item_id');
    }

    /**
     * Playlist de fond active (jouée quand la file des invités est vide).
     *
     * @return BelongsTo<Playlist, $this>
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function isOpen(): bool
    {
        return $this->status === RoomStatus::Open;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->host_user_id === $user->id;
    }

    /**
     * Incrémente la version et marque l'activité. À appeler dans la transaction
     * qui détient le verrou sur la ligne, juste avant `save()`.
     */
    public function bumpVersion(): static
    {
        $this->version++;
        $this->last_activity_at = now();

        return $this;
    }

    /**
     * Position de lecture "maintenant", extrapolée depuis le dernier point connu.
     * En lecture : position_ms + temps écoulé ; sinon : position_ms figée.
     */
    public function currentPositionMs(): int
    {
        if ($this->playback_status !== PlaybackStatus::Playing || $this->position_updated_at === null) {
            return $this->position_ms;
        }

        $elapsed = max(0, now()->getTimestampMs() - $this->position_updated_at->getTimestampMs());

        return $this->position_ms + $elapsed;
    }

    /**
     * Charge tout ce dont les API Resources ont besoin pour sérialiser l'état complet.
     * `load` (et non `loadMissing`) : après une mutation, on veut des relations fraîches.
     */
    public function loadState(): static
    {
        return $this->load([
            'host',
            'playlist',
            'currentItem.track',
            'currentItem.guest',
            'currentItem.user',
            'currentItem.playlist',
            'queue.track',
            'queue.guest',
            'queue.user',
            'queue.playlist',
        ]);
    }
}
