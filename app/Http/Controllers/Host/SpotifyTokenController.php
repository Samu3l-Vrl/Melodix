<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\Spotify\SpotifyTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /host/spotify/token — jeton d'accès Spotify de l'hôte connecté, toujours valide.
 *
 * Appelé par le callback `getOAuthToken` du Web Playback SDK (qui redemande un jeton
 * quand le sien expire). Le refresh token, lui, ne quitte jamais le serveur.
 */
class SpotifyTokenController extends Controller
{
    public function __invoke(Request $request, SpotifyTokenService $tokens): JsonResponse
    {
        $user = $request->user();

        return response()
            ->json([
                'access_token' => $tokens->freshAccessToken($user),
                'expires_at' => $user->spotify_expires_at?->getTimestampMs(),
            ])
            // Un jeton ne doit jamais être mis en cache par le navigateur ou un proxy.
            ->header('Cache-Control', 'no-store');
    }
}
