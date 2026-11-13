<?php

/*
 * Réglages propres au jukebox (tout ce qui n'est pas une clé d'API tierce).
 */
return [

    // URL de base joignable par les téléphones des invités, encodée dans le QR code.
    // En local : l'adresse IP de la machine sur le réseau Wi-Fi (ex. http://192.168.1.20).
    // Vide = APP_URL.
    'public_url' => env('JUKEBOX_PUBLIC_URL'),

    // Un salon sans activité depuis ce nombre d'heures est fermé par rooms:close-inactive.
    'inactivity_hours' => (int) env('JUKEBOX_INACTIVITY_HOURS', 12),

    'playlist' => [
        // Nombre maximal de morceaux importés depuis une playlist (Spotify : 100 par appel, YouTube : 50).
        'max_tracks' => (int) env('JUKEBOX_PLAYLIST_MAX_TRACKS', 500),
        // Un morceau joué depuis moins de N minutes est sauté par la playlist (s'il reste d'autres choix).
        'recent_minutes' => 60,
    ],

    'search' => [
        // Timeout (secondes) de chaque appel à une source musicale.
        'timeout' => 4,
        // Nombre de résultats demandés à chaque source.
        'per_provider' => 10,
        // Nombre maximal de résultats renvoyés après fusion.
        'max_results' => 20,
        // Durée de vie du cache des recherches (secondes) : 1 h.
        'cache_ttl' => 3600,
    ],

];
