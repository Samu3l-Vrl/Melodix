<?php

namespace App\Data;

/**
 * Résultat d'une recherche multi-source : les morceaux fusionnés + l'état de chaque source.
 */
final readonly class SearchOutcome
{
    public const SOURCE_OK = 'ok';

    public const SOURCE_ERROR = 'error';

    public const SOURCE_DISABLED = 'disabled';

    /**
     * @param  list<TrackData>  $results  Résultats entrelacés et dédoublonnés.
     * @param  array<string, string>  $sources  provider => ok | error | disabled.
     */
    public function __construct(
        public array $results,
        public array $sources,
    ) {}
}
