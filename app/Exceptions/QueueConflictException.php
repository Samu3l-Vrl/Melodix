<?php

namespace App\Exceptions;

/**
 * L'action de l'hôte ne correspond plus à l'état réel de la file (HTTP 409) :
 * élément déjà joué/retiré, ou liste de réordonnancement périmée.
 * Le client doit resynchroniser son état puis réessayer.
 */
class QueueConflictException extends JukeboxException
{
    public function __construct(string $message = 'La file a changé entre-temps. Réessayez.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'queue_conflict';
    }
}
