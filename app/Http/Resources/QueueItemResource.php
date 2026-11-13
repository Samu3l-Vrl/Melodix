<?php

namespace App\Http\Resources;

use App\Enums\QueueItemSource;
use App\Models\QueueItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialisation d'un élément de file, avec son morceau et son auteur.
 * Aligné sur le type TS `QueueItem`. Suppose `track`, `guest` et `user` chargés.
 *
 * @mixin QueueItem
 */
class QueueItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'status' => $this->status->value,
            'track' => (new TrackResource($this->track))->resolve($request),
            'added_by' => $this->author(),
        ];
    }

    /**
     * Tableau PHP "à plat" (sans objets Resource imbriqués), utilisé pour les
     * payloads d'events et les props : identique à ce que le client reçoit en JSON.
     *
     * @return array<string, mixed>
     */
    public static function plain(QueueItem $item): array
    {
        $item->loadMissing(['track', 'guest', 'user', 'playlist']);

        return (new self($item))->resolve(request());
    }

    /**
     * L'auteur affiché à côté du morceau. On n'expose que le type et le nom,
     * jamais d'identifiant interne ni de jeton.
     *
     * @return array{type: 'guest'|'host'|'playlist', name: string}
     */
    private function author(): array
    {
        return match ($this->source) {
            QueueItemSource::Playlist => ['type' => 'playlist', 'name' => $this->playlist?->name ?? 'Playlist'],
            QueueItemSource::Host => ['type' => 'host', 'name' => $this->user?->name ?? 'Hôte'],
            QueueItemSource::Guest => ['type' => 'guest', 'name' => $this->guest?->nickname ?? 'Invité'],
        };
    }
}
