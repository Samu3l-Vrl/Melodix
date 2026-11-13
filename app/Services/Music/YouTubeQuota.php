<?php

namespace App\Services\Music;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Compteur local du quota YouTube Data API (10 000 unités/jour par défaut).
 *
 * Google ne fournit pas d'endpoint "quota restant" : on tient donc notre propre
 * comptabilité pour arrêter d'appeler l'API AVANT de se faire refuser, et pour
 * garder une marge. Le quota Google se réinitialise à minuit, heure du Pacifique.
 */
class YouTubeQuota
{
    public const COST_SEARCH = 100;

    public const COST_VIDEOS = 1;

    private const TIMEZONE = 'America/Los_Angeles';

    /** Vrai s'il reste au moins `$cost` unités dans le budget du jour. */
    public function canSpend(int $cost): bool
    {
        return $this->used() + $cost <= (int) config('services.youtube.daily_quota');
    }

    /** Enregistre une dépense (appelé juste après un appel d'API réussi ou non : Google facture les deux). */
    public function spend(int $cost): void
    {
        $key = $this->key();

        // add() ne crée la clé que si elle n'existe pas, avec une expiration à la prochaine remise à zéro ;
        // increment() est ensuite atomique côté Redis (pas de perte de comptage entre requêtes simultanées).
        Cache::add($key, 0, $this->secondsUntilReset());
        Cache::increment($key, $cost);
    }

    /** Google a répondu "quotaExceeded" : on considère le budget du jour comme épuisé. */
    public function markExhausted(): void
    {
        Cache::put($this->key(), (int) config('services.youtube.daily_quota'), $this->secondsUntilReset());
    }

    public function used(): int
    {
        return (int) Cache::get($this->key(), 0);
    }

    private function key(): string
    {
        return 'youtube:quota:'.CarbonImmutable::now(self::TIMEZONE)->format('Y-m-d');
    }

    private function secondsUntilReset(): int
    {
        $now = CarbonImmutable::now(self::TIMEZONE);

        return max(60, (int) $now->diffInSeconds($now->addDay()->startOfDay(), true));
    }
}
