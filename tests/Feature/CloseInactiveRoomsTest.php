<?php

// tests/Feature/CloseInactiveRoomsTest.php — commande planifiée rooms:close-inactive.

use App\Models\Room;
use App\Models\Track;
use App\Services\QueueService;
use Illuminate\Console\Scheduling\Schedule;

it('ferme uniquement les salons inactifs depuis plus de 12 heures', function () {
    $stale = Room::factory()->inactiveFor(13)->create();
    $recent = Room::factory()->inactiveFor(11)->create();
    $alreadyClosed = Room::factory()->closed()->inactiveFor(48)->create();

    $this->artisan('rooms:close-inactive')
        ->expectsOutputToContain('1 salon(s) fermé(s)')
        ->assertSuccessful();

    expect($stale->fresh()->isOpen())->toBeFalse()
        ->and($recent->fresh()->isOpen())->toBeTrue()
        ->and($alreadyClosed->fresh()->version)->toBe(0); // pas retouché
});

it('considère un ajout en file comme de l\'activité', function () {
    $room = Room::factory()->inactiveFor(20)->create();

    app(QueueService::class)->add($room, Track::factory()->create(), makeGuest($room));

    $this->artisan('rooms:close-inactive')->assertSuccessful();

    expect($room->fresh()->isOpen())->toBeTrue();
});

it('accepte un seuil personnalisé', function () {
    $room = Room::factory()->inactiveFor(3)->create();

    $this->artisan('rooms:close-inactive', ['--hours' => 2])->assertSuccessful();

    expect($room->fresh()->isOpen())->toBeFalse();
});

it('est planifiée toutes les heures', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'rooms:close-inactive'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});
