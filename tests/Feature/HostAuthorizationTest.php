<?php

// tests/Feature/HostAuthorizationTest.php — droits de l'hôte propriétaire vs autre hôte vs invité.

use App\Models\User;

/**
 * Toutes les actions réservées au propriétaire du salon.
 *
 * @return array<string, array{string, string, array<string, mixed>}>
 */
function hostActions(): array
{
    return [
        'playback' => ['POST', '/host/rooms/{code}/playback', ['action' => 'pause']],
        'advance' => ['POST', '/host/rooms/{code}/queue/advance', ['item_id' => null]],
        'reorder' => ['PUT', '/host/rooms/{code}/queue/reorder', ['order' => [1]]],
        'remove' => ['DELETE', '/host/rooms/{code}/queue/1', []],
    ];
}

it('refuse les actions hôte à un invité (non authentifié), même avec un jeton invité', function (string $method, string $uri, array $payload) {
    $room = makeRoom();
    $guest = makeGuest($room);

    $this->json($method, str_replace('{code}', $room->code, $uri), $payload, guestHeaders($guest))
        ->assertUnauthorized();

    expect($room->fresh()->version)->toBe(0);
})->with(hostActions());

it('refuse les actions hôte à un hôte qui ne possède pas le salon', function (string $method, string $uri, array $payload) {
    $room = makeRoom();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->json($method, str_replace('{code}', $room->code, $uri), $payload)
        ->assertForbidden();

    expect($room->fresh()->version)->toBe(0);
})->with(hostActions());

it('autorise le propriétaire à piloter son salon', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);
    [$first, $second] = queueTracks($room, 2);

    $this->actingAs($host)
        ->postJson("/host/rooms/{$room->code}/playback", ['action' => 'play'])
        ->assertOk()
        ->assertJsonPath('playback.status', 'playing')
        ->assertJsonPath('playback.current.id', $first->id);

    $this->actingAs($host)
        ->postJson("/host/rooms/{$room->code}/queue/advance", ['item_id' => $first->id])
        ->assertOk()
        ->assertJsonPath('playback.current.id', $second->id);
});

it('réserve l\'écran hôte au propriétaire', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);

    $this->get("/room/{$room->code}/host")->assertRedirect('/');
    $this->actingAs(User::factory()->create())->get("/room/{$room->code}/host")->assertForbidden();

    $this->actingAs($host)->get("/room/{$room->code}/host")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Host/HostView')
            ->where('room.code', $room->code)
            ->where('host.spotify_premium', true)
            ->has('join_url'));
});

it('ouvre la télécommande invité à tout le monde', function () {
    $room = makeRoom();

    $this->get("/room/{$room->code}/join")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Guest/GuestView')
            ->where('room.code', $room->code)
            ->where('room.version', 0));
});

it('n\'expose jamais les jetons Spotify dans les props', function () {
    $host = User::factory()->create(['spotify_access_token' => 'TOP-SECRET-ACCESS', 'spotify_refresh_token' => 'TOP-SECRET-REFRESH']);
    $room = makeRoom($host);

    $html = $this->actingAs($host)->get("/room/{$room->code}/host")->assertOk()->getContent();

    expect($html)->not->toContain('TOP-SECRET-ACCESS')->not->toContain('TOP-SECRET-REFRESH');
});

it('construit l\'URL du QR code à partir de JUKEBOX_PUBLIC_URL', function () {
    config(['jukebox.public_url' => 'http://192.168.1.20/']);
    $host = User::factory()->create();
    $room = makeRoom($host);

    $this->actingAs($host)->get("/room/{$room->code}/host")
        ->assertInertia(fn ($page) => $page->where('join_url', "http://192.168.1.20/room/{$room->code}/join"));
});

it('permet au propriétaire de fermer son salon', function () {
    $host = User::factory()->create();
    $room = makeRoom($host);

    $this->actingAs(User::factory()->create())->post("/host/rooms/{$room->code}/close")->assertForbidden();
    $this->actingAs($host)->post("/host/rooms/{$room->code}/close")->assertRedirect('/');

    expect($room->fresh()->isOpen())->toBeFalse();
    $this->get("/room/{$room->code}/join")->assertNotFound();
});
