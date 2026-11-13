<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Authentification de l'hôte par OAuth Spotify (Authorization Code via Socialite).
 * Il n'existe aucun autre moyen de se connecter : pas de mot de passe applicatif.
 */
class SpotifyAuthController extends Controller
{
    /**
     * Scopes demandés :
     *  - streaming : lire dans le navigateur avec le Web Playback SDK (Premium requis) ;
     *  - user-read-email, user-read-private : exigés par le SDK, et `product` (premium/free) vient de là ;
     *  - user-read-playback-state, user-modify-playback-state : lancer un morceau sur l'appareil du SDK ;
     *  - playlist-read-private, playlist-read-collaborative : choisir une playlist de fond.
     */
    public const SCOPES = [
        'streaming',
        'user-read-email',
        'user-read-private',
        'user-read-playback-state',
        'user-modify-playback-state',
        // Lire les playlists de l'hôte (privées et collaboratives) pour la playlist de fond.
        'playlist-read-private',
        'playlist-read-collaborative',
    ];

    /** Étape 1 : redirige l'hôte vers la page de consentement Spotify. */
    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('spotify')
            // setScopes (et non scopes) : remplace les scopes par défaut du driver au lieu de s'y ajouter.
            ->setScopes(self::SCOPES)
            ->redirect();
    }

    /** Étape 2 : Spotify renvoie l'hôte ici avec un `code`, échangé contre des jetons. */
    public function callback(Request $request): RedirectResponse
    {
        try {
            // Socialite vérifie le paramètre `state` (anti-CSRF) puis appelle /api/token et /v1/me.
            $spotifyUser = Socialite::driver('spotify')->user();
        } catch (Throwable $e) {
            // Refus de l'hôte ("access_denied"), state invalide, Spotify en panne…
            report($e);

            return redirect()->route('home')->with('error', 'La connexion à Spotify a échoué. Réessayez.');
        }

        $user = User::updateOrCreate(
            ['spotify_id' => (string) $spotifyUser->getId()],
            [
                'name' => $spotifyUser->getName() ?: $spotifyUser->getNickname() ?: 'Hôte',
                'avatar' => $spotifyUser->getAvatar(),
                // Réévalué à chaque connexion : l'hôte a pu passer Premium (ou ne plus l'être).
                'spotify_product' => $spotifyUser->getRaw()['product'] ?? null,
                'spotify_access_token' => $spotifyUser->token,
                'spotify_refresh_token' => $spotifyUser->refreshToken,
                'spotify_expires_at' => now()->addSeconds((int) ($spotifyUser->expiresIn ?? 3600)),
            ],
        );

        Auth::login($user);
        // Nouvel identifiant de session après authentification : protège contre la fixation de session.
        $request->session()->regenerate();

        return redirect()->route('home');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
