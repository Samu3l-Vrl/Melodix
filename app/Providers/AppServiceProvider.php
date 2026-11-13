<?php

namespace App\Providers;

use App\Models\Room;
use App\Services\Music\ProviderRegistry;
use App\Services\Music\SpotifyProvider;
use App\Services\Music\YouTubeProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Spotify\Provider as SpotifySocialiteProvider;

/**
 * Câblage applicatif : conteneur de services, liaison de route, réglages globaux.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton : une seule instance par requête, construite avec les sources de la V0.
        // Pour ajouter Deezer/Apple Music, il suffira d'ajouter leur MusicProvider à cette liste.
        // L'ordre compte : c'est celui de l'entrelacement des résultats (Spotify d'abord).
        $this->app->singleton(ProviderRegistry::class, fn ($app) => new ProviderRegistry([
            $app->make(SpotifyProvider::class),
            $app->make(YouTubeProvider::class),
        ]));
    }

    public function boot(): void
    {
        // Réponses JSON "à plat" ({code, version, …}) plutôt qu'enveloppées dans {"data": …} :
        // les types TypeScript correspondent alors exactement aux API Resources.
        JsonResource::withoutWrapping();

        // En développement et en test, toute relation chargée paresseusement (N+1) ou tout attribut
        // inconnu lève une exception au lieu de passer inaperçu.
        Model::shouldBeStrict(! $this->app->isProduction());

        // {room} dans une URL = code à 4 lettres, insensible à la casse ("abcd" -> salon "ABCD").
        Route::bind('room', fn (string $code) => Room::query()
            ->where('code', strtoupper($code))
            ->firstOrFail());

        // Spotify n'est pas un driver natif de Socialite : on l'enregistre via SocialiteProviders.
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('spotify', SpotifySocialiteProvider::class);
        });
    }
}
