<?php

namespace App\Services;

use App\Enums\PlaybackStatus;
use App\Enums\QueueItemStatus;
use App\Events\PlaybackStateChanged;
use App\Exceptions\RoomClosedException;
use App\Models\Room;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Mutations de l'état de lecture (play / pause / seek). Le serveur est la source de
 * vérité : le lecteur de l'hôte SUIT cet état, il ne le définit pas.
 *
 * Modèle de temps : on stocke un point de référence (position_ms à l'instant
 * position_updated_at). Tant que le statut est "playing", la position réelle vaut
 * position_ms + (maintenant - position_updated_at). Aucun "tick" périodique n'est
 * nécessaire, donc aucune écriture en base pendant qu'un morceau joue.
 */
class PlaybackService
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly RoomBroadcaster $broadcaster,
    ) {}

    /**
     * Lance ou reprend la lecture. Sans morceau courant, démarre le premier de la file.
     *
     * @param  int|null  $positionMs  Position réelle du lecteur hôte, si connue.
     */
    public function play(Room $room, ?int $positionMs = null): bool
    {
        if ($room->current_queue_item_id === null) {
            // "expected = null" : si un autre appel a déjà démarré un morceau, advance() ne fera rien.
            return $this->queue->advance($room, null, QueueItemStatus::Played);
        }

        return $this->mutate($room, function (Room $locked) use ($positionMs) {
            // currentPositionMs() AVANT de changer le statut : si on était déjà en lecture,
            // on repart de la position extrapolée et non de l'ancien point de référence.
            $locked->position_ms = $this->clamp($locked, $positionMs ?? $locked->currentPositionMs());
            $locked->playback_status = PlaybackStatus::Playing;
        });
    }

    /** Met en pause en figeant la position (fournie par l'hôte, sinon extrapolée). */
    public function pause(Room $room, ?int $positionMs = null): bool
    {
        return $this->mutate($room, function (Room $locked) use ($positionMs) {
            $locked->position_ms = $this->clamp($locked, $positionMs ?? $locked->currentPositionMs());
            $locked->playback_status = PlaybackStatus::Paused;
        });
    }

    /** Déplace le point de référence (seek explicite ou correction de dérive), sans changer le statut. */
    public function seek(Room $room, int $positionMs): bool
    {
        return $this->mutate($room, function (Room $locked) use ($positionMs) {
            $locked->position_ms = $this->clamp($locked, $positionMs);
        });
    }

    /**
     * Change la durée des transitions entre morceaux. C'est un réglage du salon : il est enregistré
     * et diffusé même quand rien ne joue (contrairement à play/pause/seek), et ne touche pas à la position.
     */
    public function setTransition(Room $room, int $seconds): bool
    {
        $changed = DB::transaction(function () use ($room, $seconds) {
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new RoomClosedException;
            }

            if ($locked->transition_seconds === $seconds) {
                return false;
            }

            $locked->transition_seconds = $seconds;
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
     * Gabarit commun : verrou, mutation, horodatage, version, puis diffusion après commit.
     * Sans morceau courant il n'y a rien à piloter : aucune mutation, aucune version.
     *
     * @param  Closure(Room): void  $apply
     */
    private function mutate(Room $room, Closure $apply): bool
    {
        $changed = DB::transaction(function () use ($room, $apply) {
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new RoomClosedException;
            }

            if ($locked->current_queue_item_id === null) {
                return false;
            }

            $apply($locked);

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

    /** Borne la position entre 0 et la durée du morceau courant. */
    private function clamp(Room $room, int $positionMs): int
    {
        $duration = $room->currentItem?->track?->duration_ms;

        return max(0, $duration !== null ? min($positionMs, $duration) : $positionMs);
    }
}
