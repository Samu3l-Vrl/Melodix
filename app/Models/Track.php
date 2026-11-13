<?php

namespace App\Models;

use App\Enums\Provider;
use Database\Factories\TrackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Morceau normalisé, indépendant de la source. Unique par (provider, provider_track_id).
 *
 * @property int $id
 * @property Provider $provider
 * @property string $provider_track_id
 * @property string $title
 * @property string $artist
 * @property string|null $album_art_url
 * @property int $duration_ms
 */
class Track extends Model
{
    /** @use HasFactory<TrackFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'provider_track_id',
        'title',
        'artist',
        'album_art_url',
        'duration_ms',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'duration_ms' => 'integer',
        ];
    }

    /** @return HasMany<QueueItem, $this> */
    public function queueItems(): HasMany
    {
        return $this->hasMany(QueueItem::class);
    }
}
