<?php

namespace App\Services;

use App\Models\Room;
use Closure;
use RuntimeException;

/**
 * Génère des codes de salon à 4 lettres, faciles à lire à voix haute et à taper.
 */
class RoomCodeGenerator
{
    /**
     * 24 lettres : l'alphabet sans I ni O, trop faciles à confondre avec 1 et 0
     * (ou entre elles) sur un écran de TV. Aucun chiffre : 0 et 1 sont donc exclus d'office.
     * 24^4 = 331 776 codes possibles.
     */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const LENGTH = 4;

    /** Garde-fou : au-delà, l'espace de codes est saturé et il faut purger les vieux salons. */
    public const MAX_ATTEMPTS = 50;

    /**
     * @param  (Closure(): string)|null  $randomizer  Source de codes candidats ; injectable
     *                                                pour rendre les collisions testables.
     */
    public function __construct(private readonly ?Closure $randomizer = null) {}

    /**
     * Renvoie un code qui n'existe pas encore en base.
     *
     * Attention : ce test d'existence n'est PAS atomique. Deux créations simultanées
     * peuvent tirer le même code ; c'est l'index unique `rooms.code` qui tranche,
     * et RoomService réessaie dans ce cas.
     */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = $this->candidate();

            if (! Room::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Impossible de générer un code de salon unique.');
    }

    /** Tire un code candidat (sans vérifier l'unicité). */
    public function candidate(): string
    {
        if ($this->randomizer !== null) {
            return ($this->randomizer)();
        }

        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            // random_int : générateur cryptographique, pas de biais ni de graine prévisible.
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** Vrai si la chaîne a la forme d'un code de salon (utile pour valider une saisie). */
    public static function isValid(string $code): bool
    {
        return preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $code) === 1;
    }
}
