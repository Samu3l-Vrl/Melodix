<?php

namespace App\Enums;

/**
 * État du lecteur du salon, tel que le serveur le connaît.
 * idle = aucun morceau courant ; playing / paused = un morceau courant existe.
 */
enum PlaybackStatus: string
{
    case Idle = 'idle';
    case Playing = 'playing';
    case Paused = 'paused';
}
