<?php

// tests/Feature/GuestAuthTest.php — entrée d'un invité (pseudo -> jeton) et middleware ResolveGuest.

use App\Events\GuestJoined;
use App\Models\Guest;
use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

it('crée un invité et renvoie son jeton une seule fois', function () {
    $room = makeRoom();

    $response = $this->postJson("/api/rooms/{$room->code}/guests", ['nickname' => '  Léa  '])
        ->assertCreated()
        ->assertJsonPath('guest.nickname', 'Léa') // espaces superflus retirés
        ->assertJsonStructure(['guest' => ['id', 'nickname'], 'token']);

    $guest = Guest::query()->sole();

    expect($response->json('token'))->toHaveLength(40)->toBe($guest->token)
        ->and($guest->room_id)->toBe($room->id)
        ->and($guest->last_seen_at)->not->toBeNull();
});

it('refuse un pseudo vide ou trop long', function (string $nickname) {
    $room = makeRoom();

    $this->postJson("/api/rooms/{$room->code}/guests", ['nickname' => $nickname])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nickname');
})->with(['', '   ', str_repeat('a', 25)]);

it('prévient l\'hôte sur son canal privé quand un invité rejoint', function () {
    Event::fake([GuestJoined::class]);
    $room = makeRoom();

    $this->postJson("/api/rooms/{$room->code}/guests", ['nickname' => 'Léa'])->assertCreated();

    Event::assertDispatched(GuestJoined::class, function (GuestJoined $event) use ($room) {
        $channel = $event->broadcastOn()[0];

        return $channel instanceof PrivateChannel
            && $channel->name === "private-room.{$room->code}.host"
            && $event->broadcastWith()['guest']['nickname'] === 'Léa'
            && $event->broadcastWith()['guests_count'] === 1;
    });
});

it('rejette les appels invités sans jeton valide', function (?string $token) {
    $room = makeRoom();
    $headers = $token === null ? [] : ['X-Guest-Token' => $token];

    $this->getJson("/api/rooms/{$room->code}/guests/me", $headers)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'guest_unauthenticated');

    $this->getJson("/api/rooms/{$room->code}/search?q=daft", $headers)->assertUnauthorized();
    $this->postJson("/api/rooms/{$room->code}/queue", ['provider' => 'youtube', 'provider_track_id' => 'abc'], $headers)
        ->assertUnauthorized();
})->with([
    'absent' => [null],
    'trop court' => ['abc'],
    'inconnu' => [str_repeat('x', 40)],
]);

it('rejette un jeton valide utilisé dans un autre salon', function () {
    $roomA = makeRoom();
    $roomB = makeRoom();
    $guest = makeGuest($roomA);

    $this->getJson("/api/rooms/{$roomA->code}/guests/me", guestHeaders($guest))->assertOk();
    $this->getJson("/api/rooms/{$roomB->code}/guests/me", guestHeaders($guest))->assertUnauthorized();
});

it('permet de reprendre une session avec un jeton stocké', function () {
    $room = makeRoom();
    $guest = makeGuest($room, 'Noé');

    $this->getJson("/api/rooms/{$room->code}/guests/me", guestHeaders($guest))
        ->assertOk()
        ->assertExactJson(['guest' => ['id' => $guest->id, 'nickname' => 'Noé']]);
});

it('met à jour last_seen_at, au plus une fois par minute', function () {
    // freezeSecond : la colonne est précise à la seconde, on fige donc l'horloge sur une seconde ronde.
    $this->freezeSecond();
    $room = makeRoom();
    $guest = makeGuest($room);
    $guest->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

    $this->getJson("/api/rooms/{$room->code}/guests/me", guestHeaders($guest))->assertOk();
    $firstSeen = $guest->fresh()->last_seen_at;
    expect($firstSeen->equalTo(now()))->toBeTrue();

    // 30 s plus tard : pas de nouvelle écriture.
    $this->travel(30)->seconds();
    $this->getJson("/api/rooms/{$room->code}/guests/me", guestHeaders($guest))->assertOk();
    expect($guest->fresh()->last_seen_at->equalTo($firstSeen))->toBeTrue();

    // 2 min plus tard : écriture.
    $this->travel(2)->minutes();
    $this->getJson("/api/rooms/{$room->code}/guests/me", guestHeaders($guest))->assertOk();
    expect($guest->fresh()->last_seen_at->gt($firstSeen))->toBeTrue();
});

it('refuse de rejoindre un salon fermé', function () {
    $room = Room::factory()->closed()->create();

    $this->postJson("/api/rooms/{$room->code}/guests", ['nickname' => 'Léa'])
        ->assertStatus(410)
        ->assertJsonPath('code', 'room_closed');
});

it('renvoie 404 pour un code de salon inconnu', function () {
    $this->postJson('/api/rooms/ZZZZ/guests', ['nickname' => 'Léa'])->assertNotFound();
});
