<?php

namespace App\Services\Spotify;

use App\Exceptions\SpotifyAuthException;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fournit un jeton d'accès Spotify valide pour l'hôte, en le rafraîchissant au besoin.
 * Ce jeton (scope `streaming`) alimente le Web Playback SDK dans le navigateur de l'hôte.
 */
class SpotifyTokenService
{
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    /** On rafraîchit si le jeton expire dans moins de 60 s : il doit survivre au trajet jusqu'au SDK. */
    public const REFRESH_MARGIN_SECONDS = 60;

    /** @throws SpotifyAuthException Si aucun jeton valide ne peut être obtenu. */
    public function freshAccessToken(User $user): string
    {
        if (! $this->needsRefresh($user)) {
            return (string) $user->spotify_access_token;
        }

        try {
            // Verrou par hôte : le SDK et l'adaptateur peuvent demander un jeton en même temps.
            // Spotify peut faire tourner le refresh token ; deux rafraîchissements simultanés
            // risqueraient d'enregistrer un jeton déjà invalidé par l'autre.
            return Cache::lock("spotify-refresh:{$user->id}", 10)->block(5, function () use ($user) {
                // Relecture sous verrou : une requête concurrente a peut-être déjà fait le travail.
                $user->refresh();

                return $this->needsRefresh($user) ? $this->refresh($user) : (string) $user->spotify_access_token;
            });
        } catch (LockTimeoutException) {
            throw new SpotifyAuthException('Spotify met trop de temps à répondre. Réessayez.');
        }
    }

    public function needsRefresh(User $user): bool
    {
        return blank($user->spotify_access_token)
            || $user->spotify_expires_at === null
            || $user->spotify_expires_at->lte(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }

    private function refresh(User $user): string
    {
        if (blank($user->spotify_refresh_token)) {
            throw new SpotifyAuthException;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth((string) config('services.spotify.client_id'), (string) config('services.spotify.client_secret'))
                ->timeout(5)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $user->spotify_refresh_token,
                ]);
        } catch (ConnectionException) {
            throw new SpotifyAuthException('Spotify est injoignable. Réessayez.');
        }

        $accessToken = $response->json('access_token');

        if ($response->failed() || ! is_string($accessToken)) {
            // Typiquement "invalid_grant" : l'hôte a révoqué l'accès depuis son compte Spotify.
            throw new SpotifyAuthException;
        }

        $user->update([
            'spotify_access_token' => $accessToken,
            // Spotify ne renvoie un nouveau refresh token que de temps en temps : sinon on garde l'ancien.
            'spotify_refresh_token' => $response->json('refresh_token') ?: $user->spotify_refresh_token,
            'spotify_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ]);

        return $accessToken;
    }
}
