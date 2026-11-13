<?php

/*
 * Identifiants des services tiers. Aucune valeur en dur : tout vient du .env.
 */
return [

    // https://developer.spotify.com/dashboard — sert à la fois à l'OAuth de l'hôte (Socialite)
    // et à la recherche côté serveur (client credentials).
    'spotify' => [
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
        // Doit correspondre EXACTEMENT à une "Redirect URI" déclarée dans le dashboard Spotify.
        // Spotify refuse http://localhost : en local, utiliser http://127.0.0.1.
        'redirect' => env('SPOTIFY_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://127.0.0.1'), '/').'/auth/spotify/callback'),
        // Pays utilisé pour filtrer les morceaux lisibles lors de la recherche (code ISO 3166-1 alpha-2).
        'market' => env('SPOTIFY_MARKET', 'FR'),
    ],

    // https://console.cloud.google.com — clé d'API "YouTube Data API v3".
    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
        // Budget quotidien que l'application s'autorise (le quota Google par défaut est de 10 000 unités).
        // Une marge est gardée pour ne jamais atteindre le refus côté Google.
        'daily_quota' => (int) env('YOUTUBE_DAILY_QUOTA', 9500),
    ],

];
