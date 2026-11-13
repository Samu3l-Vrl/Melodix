<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast date/heure à la milliseconde.
 *
 * Le cast "datetime" d'Eloquent écrit au format "Y-m-d H:i:s" et perd donc les
 * millisecondes. Or `rooms.position_updated_at` sert à calculer la progression
 * de lecture : une précision à la seconde créerait jusqu'à 1 s de dérive.
 *
 * @implements CastsAttributes<CarbonImmutable|null, CarbonInterface|string|null>
 */
class AsPreciseDateTime implements CastsAttributes
{
    private const FORMAT = 'Y-m-d H:i:s.v';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        // PostgreSQL supprime les zéros de fin ("…:00.5", "…:00") : on parse donc de façon souple.
        return CarbonImmutable::parse($value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // On passe par getTimestampMs() (qui ARRONDIT à la milliseconde) plutôt que par le format "v"
        // (qui TRONQUE) : la valeur stockée est ainsi exactement celle que renvoie now()->getTimestampMs()
        // au même instant, et les calculs "maintenant - position_updated_at" ne dérivent pas d'1 ms.
        $milliseconds = CarbonImmutable::parse($value)->getTimestampMs();

        return CarbonImmutable::createFromTimestampMs($milliseconds, 'UTC')->format(self::FORMAT);
    }
}
