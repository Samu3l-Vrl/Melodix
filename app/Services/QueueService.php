<?php

namespace App\Services;

use App\Enums\PlaybackStatus;
use App\Enums\QueueItemStatus;
use App\Events\PlaybackStateChanged;
use App\Events\QueueUpdated;
use App\Exceptions\DuplicateTrackException;
use App\Exceptions\QueueConflictException;
use App\Exceptions\RoomClosedException;
use App\Models\Guest;
use App\Models\QueueItem;
use App\Models\Room;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Toutes les mutations de la file d'attente d'un salon.
 *
 * Règle d'or : chaque mutation s'exécute dans une transaction qui commence par
 * verrouiller la ligne `rooms` (SELECT … FOR UPDATE). Les mutations d'un même salon
 * sont donc strictement sérialisées : pas de course sur `position`, pas de doublon
 * inséré "en même temps", et `version` augmente de exactement 1 par mutation.
 * La diffusion a lieu APRÈS le commit, pour ne jamais annoncer un état annulé.
 */
class QueueService
{
    public function __construct(private readonly RoomBroadcaster $broadcaster) {}

    /**
     * Ajoute un morceau en fin de file (FIFO).
     *
     * @throws DuplicateTrackException Si le morceau est déjà en attente ou en cours (409).
     * @throws RoomClosedException Si le salon est fermé (410).
     */
    public function add(Room $room, Track $track, Guest|User $author): QueueItem
    {
        $item = DB::transaction(function () use ($room, $track, $author) {
            $locked = $this->lock($room);

            $alreadyQueued = QueueItem::query()
                ->where('room_id', $locked->id)
                ->where('track_id', $track->id)
                ->whereIn('status', QueueItemStatus::active())
                ->exists();

            if ($alreadyQueued) {
                throw new DuplicateTrackException;
            }

            // max(position) + 1 sur TOUS les éléments du salon (y compris joués) :
            // les positions ne sont jamais réutilisées, l'ordre d'arrivée reste lisible en base.
            // Sans le verrou, deux ajouts simultanés liraient le même max.
            $position = (int) QueueItem::query()->where('room_id', $locked->id)->max('position') + 1;

            $item = QueueItem::create([
                'room_id' => $locked->id,
                'track_id' => $track->id,
                'added_by_guest_id' => $author instanceof Guest ? $author->id : null,
                'added_by_user_id' => $author instanceof User ? $author->id : null,
                'position' => $position,
                'status' => QueueItemStatus::Queued,
            ]);

            $locked->bumpVersion()->save();

            return $item;
        });

        $room->refresh();
        $this->broadcaster->send(QueueUpdated::added($room, $item));

        return $item;
    }

    /**
     * Retire un élément en attente (action hôte).
     *
     * @throws QueueConflictException Si l'élément n'est plus en attente (déjà joué, retiré…).
     */
    public function remove(Room $room, int $itemId): void
    {
        DB::transaction(function () use ($room, $itemId) {
            $locked = $this->lock($room);

            // L'élément est relu SOUS le verrou : son statut a pu changer depuis l'affichage côté hôte.
            $item = QueueItem::query()
                ->where('room_id', $locked->id)
                ->where('status', QueueItemStatus::Queued)
                ->find($itemId);

            if ($item === null) {
                throw new QueueConflictException("Ce morceau n'est plus dans la file.");
            }

            $item->delete();
            $locked->bumpVersion()->save();
        });

        $room->refresh();
        $this->broadcaster->send(QueueUpdated::removed($room, $itemId));
    }

    /**
     * Réordonne la file (action hôte). `$orderedIds` doit contenir exactement
     * les ids actuellement en attente, dans le nouvel ordre voulu.
     *
     * @param  list<int>  $orderedIds
     *
     * @throws QueueConflictException Si la liste ne correspond pas à la file réelle.
     */
    public function reorder(Room $room, array $orderedIds): void
    {
        DB::transaction(function () use ($room, $orderedIds) {
            $locked = $this->lock($room);

            $queued = QueueItem::query()
                ->where('room_id', $locked->id)
                ->where('status', QueueItemStatus::Queued)
                ->orderBy('position')
                ->get()
                ->keyBy('id');

            $expected = $queued->keys()->sort()->values()->all();
            $given = collect($orderedIds)->sort()->values()->all();

            // Un invité a ajouté un morceau (ou un morceau a démarré) entre l'affichage et le clic :
            // plutôt que deviner, on refuse et le client se resynchronise.
            if ($expected !== $given) {
                throw new QueueConflictException;
            }

            // On redistribue les positions EXISTANTES dans le nouvel ordre : elles restent
            // supérieures à celles des morceaux déjà joués et inférieures aux futurs ajouts.
            $positions = $queued->pluck('position')->sort()->values();

            foreach ($orderedIds as $index => $id) {
                $queued[$id]->update(['position' => $positions[$index]]);
            }

            $locked->bumpVersion()->save();
        });

        $room->refresh();
        $this->broadcaster->send(QueueUpdated::reordered($room, array_values($orderedIds)));
    }

    /**
     * Passe au morceau suivant : clôt le morceau courant avec `$finalStatus`
     * (played = fin naturelle, skipped = skip ou erreur) et démarre le premier en attente.
     *
     * IDEMPOTENCE : `$expectedCurrentItemId` est l'id que le client croit être en cours
     * (null = "rien ne joue"). S'il ne correspond pas à l'état réel, quelqu'un a déjà
     * avancé : on ne fait rien. Sans cela, un double "ended" (ou un double-clic sur Skip)
     * ferait sauter un morceau.
     *
     * @return bool Vrai si l'état a changé (et a été diffusé).
     */
    public function advance(Room $room, ?int $expectedCurrentItemId, QueueItemStatus $finalStatus): bool
    {
        $changed = DB::transaction(function () use ($room, $expectedCurrentItemId, $finalStatus) {
            $locked = $this->lock($room);

            if ($locked->current_queue_item_id !== $expectedCurrentItemId) {
                return false;
            }

            $next = QueueItem::query()
                ->where('room_id', $locked->id)
                ->where('status', QueueItemStatus::Queued)
                ->orderBy('position')
                ->first();

            // Rien en cours et rien en attente : aucun changement, donc pas de nouvelle version.
            if ($locked->current_queue_item_id === null && $next === null) {
                return false;
            }

            if ($locked->current_queue_item_id !== null) {
                QueueItem::query()
                    ->whereKey($locked->current_queue_item_id)
                    ->update(['status' => $finalStatus, 'played_at' => now()]);
            }

            if ($next !== null) {
                $next->update(['status' => QueueItemStatus::Playing]);

                $locked->current_queue_item_id = $next->id;
                $locked->playback_status = PlaybackStatus::Playing;
            } else {
                $locked->current_queue_item_id = null;
                $locked->playback_status = PlaybackStatus::Idle;
            }

            $locked->position_ms = 0;
            $locked->position_updated_at = now();
            $locked->bumpVersion()->save();

            return true;
        });

        $room->refresh();

        if ($changed) {
            $this->broadcaster->send(PlaybackStateChanged::fromRoom($room));
        }

        return $changed;
    }

    /**
     * Verrouille la ligne du salon jusqu'à la fin de la transaction courante
     * et renvoie une instance fraîche (lue APRÈS l'obtention du verrou).
     *
     * @throws RoomClosedException
     */
    private function lock(Room $room): Room
    {
        $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw new RoomClosedException;
        }

        return $locked;
    }
}
