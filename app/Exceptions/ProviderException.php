<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Erreur technique d'une source musicale (HTTP en échec, quota, réponse illisible).
 * Jamais renvoyée telle quelle au client : SearchService l'attrape et dégrade.
 */
class ProviderException extends RuntimeException {}
