<?php

// tests/Feature/ConstraintsTest.php — les contraintes d'unicité sont garanties par la BASE, pas seulement par le code.

use App\Enums\Provider;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 * Chaque insertion fautive est faite dans DB::transaction() : sous PostgreSQL, une erreur SQL
 * invalide la transaction en cours ; le SAVEPOINT créé ici permet au test de continuer.
 */

it('interdit deux salons avec le même code', function () {
    Room::factory()->create(['code' => 'ABCD']);

    DB::transaction(fn () => Room::factory()->create(['code' => 'ABCD']));
})->throws(UniqueConstraintViolationException::class);

it('interdit deux hôtes avec le même spotify_id', function () {
    User::factory()->create(['spotify_id' => 'same-spotify-id']);

    DB::transaction(fn () => User::factory()->create(['spotify_id' => 'same-spotify-id']));
})->throws(UniqueConstraintViolationException::class);

it('interdit deux invités avec le même jeton', function () {
    $room = makeRoom();
    $token = Guest::generateToken();
    Guest::factory()->create(['room_id' => $room->id, 'token' => $token]);

    DB::transaction(fn () => Guest::factory()->create(['room_id' => $room->id, 'token' => $token]));
})->throws(UniqueConstraintViolationException::class);

it('interdit deux morceaux avec le même couple (provider, provider_track_id)', function () {
    Track::factory()->create(['provider' => Provider::YouTube, 'provider_track_id' => 'dQw4w9WgXcQ']);

    DB::transaction(fn () => Track::factory()->create(['provider' => Provider::YouTube, 'provider_track_id' => 'dQw4w9WgXcQ']));
})->throws(UniqueConstraintViolationException::class);

it('autorise le même identifiant chez deux sources différentes', function () {
    Track::factory()->create(['provider' => Provider::YouTube, 'provider_track_id' => 'same-id']);
    Track::factory()->create(['provider' => Provider::Spotify, 'provider_track_id' => 'same-id']);

    expect(Track::count())->toBe(2);
});

it('chiffre les jetons Spotify en base', function () {
    $user = User::factory()->create(['spotify_access_token' => 'secret-access', 'spotify_refresh_token' => 'secret-refresh']);

    $raw = DB::table('users')->where('id', $user->id)->first();

    expect($raw->spotify_access_token)->not->toContain('secret-access')
        ->and($raw->spotify_refresh_token)->not->toContain('secret-refresh')
        ->and($user->fresh()->spotify_access_token)->toBe('secret-access');
});

it('génère des jetons invités de 40 caractères', function () {
    expect(Guest::generateToken())->toHaveLength(40)
        ->and(Guest::generateToken())->not->toBe(Guest::generateToken());
});
