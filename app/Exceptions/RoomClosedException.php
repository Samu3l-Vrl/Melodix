<?php

namespace App\Exceptions;

/**
 * Le salon est fermé : plus aucune action n'est acceptée (HTTP 410 Gone).
 */
class RoomClosedException extends JukeboxException
{
    public function __construct()
    {
        parent::__construct('Ce salon est fermé.');
    }

    public function status(): int
    {
        return 410;
    }

    public function errorCode(): string
    {
        return 'room_closed';
    }
}
