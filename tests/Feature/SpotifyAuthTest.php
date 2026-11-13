<?php

// tests/Feature/SpotifyAuthTest.php — OAuth Spotify de l'hôte et rafraîchissement automatique du jeton.

use App\Exceptions\SpotifyAuthException;
use App\Http\Controllers\Auth\SpotifyAuthController;
use App\Models\User;
use App\Services\Spotify\SpotifyTokenService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/** Fabrique le profil que Socialite renverrait après un OAuth réussi. */
function fakeSpotifyProfile(string $product = 'premium'): SocialiteUser
{
    $user = (new SocialiteUser)->setRaw(['id' => 'spotify-user-1', 'display_name' => 'Camille', 'product' => $product])->map([
        'id' => 'spotify-user-1',
        'nickname' => null,
        'name' => 'Camille',
        'email' => 'camille@example.test',
        'avatar' => 'https://i.scdn.co/image/avatar',
    ]);

    return $user->setToken('access-123')->setRefreshToken('refresh-456')->setExpiresIn(3600);
}

it('redirige l\'hôte vers Spotify avec exactement les scopes nécessaires', function () {
    $response = $this->get('/auth/spotify/redirect');

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.spotify.com/authorize')
        ->and($query['client_id'])->toBe('test-spotify-id')
        ->and($query['response_type'])->toBe('code')
        ->and(explode(' ', $query['scope']))->toEqualCanonicalizing(SpotifyAuthController::SCOPES)
        ->and($query)->toHaveKey('state'); // protection CSRF du flux OAuth
});

it('crée l\'hôte au premier retour de Spotify et le connecte', function () {
    $this->freezeSecond();
    Socialite::shouldReceive('driver->user')->andReturn(fakeSpotifyProfile());

    $this->get('/auth/spotify/callback?code=abc&state=xyz')->assertRedirect('/');

    $user = User::query()->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->spotify_id)->toBe('spotify-user-1')
        ->and($user->name)->toBe('Camille')
        ->and($user->spotify_product)->toBe('premium')
        ->and($user->hasSpotifyPremium())->toBeTrue()
        ->and($user->spotify_access_token)->toBe('access-123')
        ->and($user->spotify_refresh_token)->toBe('refresh-456')
        ->and($user->spotify_expires_at->equalTo(now()->addHour()))->toBeTrue();
});

it('met à jour l\'hôte existant au lieu d\'en créer un second', function () {
    User::factory()->create(['spotify_id' => 'spotify-user-1', 'spotify_product' => 'premium', 'name' => 'Ancien nom']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeSpotifyProfile('free'));

    $this->get('/auth/spotify/callback?code=abc&state=xyz')->assertRedirect('/');

    expect(User::count())->toBe(1)
        ->and(User::first()->name)->toBe('Camille')
        ->and(User::first()->hasSpotifyPremium())->toBeFalse();
});

it('revient à l\'accueil avec un message quand l\'OAuth échoue', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new RuntimeException('access_denied'));

    $this->get('/auth/spotify/callback?error=access_denied')
        ->assertRedirect('/')
        ->assertSessionHas('error');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('déconnecte l\'hôte', function () {
    $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});

it('renvoie le jeton courant sans appeler Spotify tant qu\'il est valide', function () {
    Http::fake();
    $host = User::factory()->create(['spotify_access_token' => 'still-valid', 'spotify_expires_at' => now()->addMinutes(30)]);

    $this->actingAs($host)->getJson('/host/spotify/token')
        ->assertOk()
        ->assertJsonPath('access_token', 'still-valid')
        ->assertHeader('Cache-Control', 'no-store, private');

    Http::assertNothingSent();
});

it('rafraîchit le jeton quand il expire dans moins de 60 secondes', function () {
    $this->freezeSecond();
    Http::fake(['accounts.spotify.com/api/token' => Http::response([
        'access_token' => 'brand-new',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        // Pas de refresh_token dans la réponse : l'ancien doit être conservé.
    ])]);
    $host = User::factory()->create([
        'spotify_access_token' => 'about-to-expire',
        'spotify_refresh_token' => 'my-refresh',
        'spotify_expires_at' => now()->addSeconds(59),
    ]);

    $this->actingAs($host)->getJson('/host/spotify/token')
        ->assertOk()
        ->assertJsonPath('access_token', 'brand-new')
        ->assertJsonPath('expires_at', now()->addHour()->getTimestampMs());

    Http::assertSent(fn (Request $r) => $r->url() === 'https://accounts.spotify.com/api/token'
        && $r['grant_type'] === 'refresh_token'
        && $r['refresh_token'] === 'my-refresh'
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('test-spotify-id:test-spotify-secret')));

    expect($host->fresh()->spotify_access_token)->toBe('brand-new')
        ->and($host->fresh()->spotify_refresh_token)->toBe('my-refresh');
});

it('ne rafraîchit pas un jeton valable encore plus de 60 secondes', function () {
    Http::fake();
    $service = app(SpotifyTokenService::class);

    expect($service->needsRefresh(User::factory()->make(['spotify_expires_at' => now()->addSeconds(61)])))->toBeFalse()
        ->and($service->needsRefresh(User::factory()->make(['spotify_expires_at' => now()->addSeconds(60)])))->toBeTrue()
        ->and($service->needsRefresh(User::factory()->make(['spotify_expires_at' => now()->subMinute()])))->toBeTrue()
        ->and($service->needsRefresh(User::factory()->make(['spotify_expires_at' => null])))->toBeTrue();
});

it('enregistre le nouveau refresh token quand Spotify en fournit un', function () {
    Http::fake(['accounts.spotify.com/api/token' => Http::response([
        'access_token' => 'new-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 3600,
    ])]);
    $host = User::factory()->expiringToken()->create();

    app(SpotifyTokenService::class)->freshAccessToken($host);

    expect($host->fresh()->spotify_refresh_token)->toBe('rotated-refresh');
});

it('demande une reconnexion quand Spotify refuse le refresh token', function () {
    Http::fake(['accounts.spotify.com/api/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $host = User::factory()->expiringToken()->create();

    $this->actingAs($host)->getJson('/host/spotify/token')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'spotify_reauth_required');

    expect(fn () => app(SpotifyTokenService::class)->freshAccessToken($host))->toThrow(SpotifyAuthException::class);
});

it('réserve l\'endpoint de jeton à un hôte connecté', function () {
    $this->getJson('/host/spotify/token')->assertUnauthorized();
});
