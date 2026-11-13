<?php

namespace App\Console\Commands;

use App\Services\RoomService;
use Illuminate\Console\Command;

/**
 * `php artisan rooms:close-inactive` — ferme les salons oubliés après la soirée.
 */
class CloseInactiveRooms extends Command
{
    /** @var string */
    protected $signature = 'rooms:close-inactive {--hours= : Seuil d\'inactivité en heures (défaut : config jukebox.inactivity_hours)}';

    /** @var string */
    protected $description = 'Ferme les salons ouverts sans activité depuis plusieurs heures (12 h par défaut)';

    public function handle(RoomService $rooms): int
    {
        $hours = (int) ($this->option('hours') ?: config('jukebox.inactivity_hours'));

        $closed = $rooms->closeInactive($hours);

        $this->info("{$closed} salon(s) fermé(s) après {$hours} h d'inactivité.");

        return self::SUCCESS;
    }
}
