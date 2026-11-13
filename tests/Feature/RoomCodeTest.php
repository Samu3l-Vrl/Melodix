<?php

// tests/Feature/RoomCodeTest.php — unicité des codes de salon et création par l'hôte.

use App\Models\Room;
use App\Models\User;
use App\Services\RoomCodeGenerator;
use App\Services\RoomService;

it('génère des codes tous différents pour des salons successifs', function () {
    $host = User::factory()->create();
    $service = app(RoomService::class);

    $codes = collect(range(1, 40))->map(fn () => $service->create($host)->code);

    expect($codes->unique())->toHaveCount(40);
    $codes->each(fn (string $code) => expect(RoomCodeGenerator::isValid($code))->toBeTrue());
});

it('retire un nouveau code quand le premier candidat est déjà pris', function () {
    Room::factory()->create(['code' => 'AAAA']);

    // Générateur truqué : propose deux fois le code déjà pris, puis un code libre.
    $candidates = ['AAAA', 'AAAA', 'BBBB'];
    $generator = new RoomCodeGenerator(function () use (&$candidates) {
        return array_shift($candidates);
    });

    expect($generator->generate())->toBe('BBBB');
});

it('abandonne proprement quand aucun code libre n\'est trouvé', function () {
    Room::factory()->create(['code' => 'AAAA']);

    $generator = new RoomCodeGenerator(fn () => 'AAAA');

    $generator->generate();
})->throws(RuntimeException::class);

it('crée un salon via POST /rooms et redirige vers l\'écran hôte', function () {
    $host = User::factory()->create();

    $response = $this->actingAs($host)->post('/rooms');

    $room = Room::query()->sole();

    $response->assertRedirect("/room/{$room->code}/host");
    expect($room->host_user_id)->toBe($host->id)
        ->and($room->version)->toBe(0)
        ->and($room->isOpen())->toBeTrue();
});

it('refuse la création de salon à un visiteur non connecté', function () {
    $this->post('/rooms')->assertRedirect('/');
    $this->postJson('/rooms')->assertUnauthorized();

    expect(Room::count())->toBe(0);
});

it('retrouve un salon quelle que soit la casse du code dans l\'URL', function () {
    $room = Room::factory()->create(['code' => 'KZPW']);

    $this->getJson('/api/rooms/kzpw/state')
        ->assertOk()
        ->assertJsonPath('code', 'KZPW');
});
