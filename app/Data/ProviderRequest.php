<?php

namespace App\Data;

/**
 * Description d'une requête HTTP de recherche, construite par un MusicProvider
 * mais envoyée par SearchService (dans un Http::pool, pour le parallélisme).
 */
final readonly class ProviderRequest
{
    /**
     * @param  array<string, scalar>  $query  Paramètres de query string.
     * @param  string|null  $bearerToken  Jeton "Authorization: Bearer" éventuel.
     */
    public function __construct(
        public string $url,
        public array $query = [],
        public ?string $bearerToken = null,
    ) {}
}
