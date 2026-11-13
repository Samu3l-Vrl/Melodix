<?php

namespace App\Services\Music;

use App\Data\ProviderRequest;
use App\Data\TrackData;
use App\Enums\Provider;
use App\Exceptions\ProviderException;
use Illuminate\Http\Client\Response;

/**
 * Contrat d'une source musicale (Spotify, YouTube ; demain Apple Music, Deezer…).
 *
 * La recherche est volontairement coupée en deux temps — "décrire la requête" puis
 * "interpréter la réponse" — pour que SearchService puisse envoyer toutes les sources
 * EN PARALLÈLE dans un seul Http::pool, au lieu de les interroger l'une après l'autre.
 */
interface MusicProvider
{
    /** Identifiant de la source. */
    public function provider(): Provider;

    /** Vrai si les clés d'API nécessaires sont présentes dans la configuration. */
    public function isConfigured(): bool;

    /**
     * Décrit la requête HTTP de recherche, sans l'envoyer.
     *
     * @throws ProviderException Si la source ne peut pas être interrogée (jeton, quota…).
     */
    public function searchRequest(string $query): ProviderRequest;

    /**
     * Transforme la réponse HTTP de recherche en morceaux normalisés.
     *
     * @return list<TrackData>
     *
     * @throws ProviderException Si la réponse est une erreur ou est inexploitable.
     */
    public function parseSearchResponse(Response $response): array;

    /**
     * Récupère un morceau par son identifiant chez la source (utilisé à l'ajout en file
     * quand le morceau n'est ni en base ni en cache). Null s'il n'existe pas.
     *
     * @throws ProviderException En cas d'erreur technique.
     */
    public function find(string $providerTrackId): ?TrackData;
}
