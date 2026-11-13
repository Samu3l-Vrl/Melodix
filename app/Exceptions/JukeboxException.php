<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base des erreurs métier du jukebox. Chaque sous-classe fixe un statut HTTP et un
 * `code` stable, que le frontend utilise pour choisir son message (ex. "doublon").
 */
abstract class JukeboxException extends RuntimeException
{
    /** Statut HTTP renvoyé au client. */
    abstract public function status(): int;

    /** Code d'erreur machine, stable dans le temps. */
    abstract public function errorCode(): string;

    /** Laravel appelle `render()` automatiquement quand l'exception remonte jusqu'au handler. */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode(),
        ], $this->status());
    }
}
