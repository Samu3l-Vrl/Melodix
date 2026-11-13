<?php

namespace App\Services;

use App\Enums\PlaybackStatus;
use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie des salons : création (avec code unique) et fermeture.
 */
class RoomService
{
    private const CREATE_ATTEMPTS = 5;

    public function __construct(private readonly RoomCodeGenerator $codes) {}

    /**
     * Crée un salon pour l'hôte. Si deux créations concurrentes tirent le même code,
     * l'index unique rejette la seconde : on retente avec un nouveau code.
     */
    public function create(User $host): Room
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                // Transaction dédiée = SAVEPOINT quand on est déjà dans une transaction :
                // sous PostgreSQL, une violation d'unicité rendrait sinon toute la transaction inutilisable.
                return DB::transaction(fn () => Room::create([
                    'code' => $this->codes->generate(),
                    'host_user_id' => $host->id,
                    'status' => RoomStatus::Open,
                    'playback_status' => PlaybackStatus::Idle,
                    'last_activity_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::CREATE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Ferme un salon : plus aucune action n'est acceptée, la lecture est arrêtée.
     * Le code reste réservé (historique) ; voir "limites connues" du README.
     */
    public function close(Room $room): void
    {
        DB::transaction(function () use ($room) {
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                return;
            }

            $locked->status = RoomStatus::Closed;
            $locked->playback_status = PlaybackStatus::Idle;
            $locked->version++;
            $locked->save();
        });

        $room->refresh();
    }

    /**
     * Ferme tous les salons ouverts sans activité depuis $hours heures.
     *
     * @return int Nombre de salons fermés.
     */
    public function closeInactive(int $hours): int
    {
        $closed = 0;

        Room::query()
            ->where('status', RoomStatus::Open)
            ->where(function ($query) use ($hours) {
                $query->where('last_activity_at', '<', now()->subHours($hours))
                    // Salon jamais utilisé : on se rabat sur sa date de création.
                    ->orWhere(fn ($q) => $q->whereNull('last_activity_at')->where('created_at', '<', now()->subHours($hours)));
            })
            // chunkById : mémoire constante même avec beaucoup de salons, et insensible
            // au fait qu'on modifie la colonne `status` filtrée pendant le parcours.
            ->chunkById(100, function ($rooms) use (&$closed) {
                foreach ($rooms as $room) {
                    $this->close($room);
                    $closed++;
                }
            });

        return $closed;
    }
}
