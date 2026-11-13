<?php

// tests/Feature/PlaybackTest.php — POST /host/rooms/{code}/playback : play, pause, seek, ended et throttle.

use App\Enums\PlaybackStatus;
use App\Enums\QueueItemStatus;
use App\Events\PlaybackStateChanged;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->host = User::factory()->create();
    $this->room = makeRoom($this->host);
    $this->url = "/host/rooms/{$this->room->code}/playback";
});

it('démarre le premier morceau de la file sur play', function () {
    $this->freezeTime();
    [$first] = queueTracks($this->room, 2);

    $this->actingAs($this->host)->postJson($this->url, ['action' => 'play'])
        ->assertOk()
        ->assertJsonPath('playback.status', 'playing')
        ->assertJsonPath('playback.current.id', $first->id)
        ->assertJsonPath('playback.current.status', 'playing')
        ->assertJsonPath('playback.position_ms', 0)
        ->assertJsonPath('playback.position_updated_at', now()->getTimestampMs())
        ->assertJsonPath('server_time', now()->getTimestampMs())
        // Le morceau en cours ne figure plus dans la file d'attente.
        ->assertJsonCount(1, 'queue');
});

it('ne fait rien sur play quand la file est vide', function () {
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'play'])
        ->assertOk()
        ->assertJsonPath('playback.status', 'idle')
        ->assertJsonPath('version', 0);
});

it('fige la position extrapolée sur pause, puis reprend au même endroit', function () {
    $this->freezeTime();
    queueTracks($this->room, 1);
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'play'])->assertOk();

    // 42,5 s de lecture plus tard, l'hôte met en pause sans préciser la position.
    $this->travel(42_500)->milliseconds();

    $this->actingAs($this->host)->postJson($this->url, ['action' => 'pause'])
        ->assertOk()
        ->assertJsonPath('playback.status', 'paused')
        ->assertJsonPath('playback.position_ms', 42_500);

    // La pause dure une minute : la position ne bouge pas.
    $this->travel(1)->minutes();
    expect($this->room->fresh()->currentPositionMs())->toBe(42_500);

    $this->actingAs($this->host)->postJson($this->url, ['action' => 'play'])
        ->assertOk()
        ->assertJsonPath('playback.status', 'playing')
        ->assertJsonPath('playback.position_ms', 42_500)
        ->assertJsonPath('playback.position_updated_at', now()->getTimestampMs());
});

it('utilise la position réelle du lecteur quand l\'hôte la fournit', function () {
    queueTracks($this->room, 1);
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'play'])->assertOk();

    $this->actingAs($this->host)->postJson($this->url, ['action' => 'pause', 'position_ms' => 12_345])
        ->assertOk()
        ->assertJsonPath('playback.position_ms', 12_345);
});

it('déplace la position sur seek sans changer le statut, bornée à la durée du morceau', function () {
    [$item] = queueTracks($this->room, 1);
    $duration = $item->track->duration_ms;
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'play'])->assertOk();

    $this->actingAs($this->host)->postJson($this->url, ['action' => 'seek', 'position_ms' => 60_000])
        ->assertOk()
        ->assertJsonPath('playback.status', 'playing')
        ->assertJsonPath('playback.position_ms', 60_000);

    $this->travel(2)->seconds();

    $this->actingAs($this->host)->postJson($this->url, ['action' => 'seek', 'position_ms' => $duration + 99_999])
        ->assertOk()
        ->assertJsonPath('playback.position_ms', $duration);
});

it('limite les mises à jour de position à une par seconde', function () {
    $this->freezeTime();
    queueTracks($this->room, 1);
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'play'])->assertOk();
    $version = $this->room->fresh()->version;

    $this->actingAs($this->host)->postJson($this->url, ['action' => 'seek', 'position_ms' => 10_000])->assertOk();

    // Deuxième seek dans la même seconde : refusé, rien n'est écrit ni diffusé.
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'seek', 'position_ms' => 20_000])
        ->assertStatus(429)
        ->assertJsonPath('code', 'seek_throttled')
        ->assertHeader('Retry-After', '1');

    expect($this->room->fresh()->version)->toBe($version + 1)
        ->and($this->room->fresh()->position_ms)->toBe(10_000);

    // Une seconde plus tard, c'est de nouveau accepté.
    $this->travel(1100)->milliseconds();
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'seek', 'position_ms' => 20_000])->assertOk();

    // play et pause ne sont pas concernés par cette limite.
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'pause'])->assertOk();
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'play'])->assertOk();
});

it('passe au morceau suivant sur ended et marque le précédent comme joué', function () {
    [$first, $second] = queueTracks($this->room, 2);
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'play'])->assertOk();

    $this->actingAs($this->host)->postJson($this->url, ['action' => 'ended', 'item_id' => $first->id])
        ->assertOk()
        ->assertJsonPath('playback.current.id', $second->id)
        ->assertJsonPath('playback.status', 'playing')
        ->assertJsonPath('playback.position_ms', 0);

    expect($first->fresh()->status)->toBe(QueueItemStatus::Played);

    // Signal "ended" en double pour le premier morceau : ignoré.
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'ended', 'item_id' => $first->id])
        ->assertOk()
        ->assertJsonPath('playback.current.id', $second->id);
});

it('valide le corps de la requête', function (array $payload, string $field) {
    $this->actingAs($this->host)->postJson($this->url, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'action inconnue' => [['action' => 'rewind'], 'action'],
    'action absente' => [[], 'action'],
    'seek sans position' => [['action' => 'seek'], 'position_ms'],
    'position négative' => [['action' => 'seek', 'position_ms' => -5], 'position_ms'],
    'ended sans item_id' => [['action' => 'ended'], 'item_id'],
]);

it('ignore pause et seek quand rien ne joue', function () {
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'pause'])->assertOk()->assertJsonPath('version', 0);
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'seek', 'position_ms' => 5000])->assertOk()->assertJsonPath('version', 0);

    expect($this->room->fresh()->playback_status)->toBe(PlaybackStatus::Idle);
});

it('extrapole la position de lecture côté serveur à la milliseconde', function () {
    $this->freezeTime();
    queueTracks($this->room, 1);
    app(QueueService::class)->advance($this->room, null, QueueItemStatus::Played);

    $this->travel(1234)->milliseconds();

    expect($this->room->fresh()->currentPositionMs())->toBe(1234);
});

// --- Transitions entre morceaux -----------------------------------------------------------

it('active par défaut une transition de 6 secondes', function () {
    $this->getJson("/api/rooms/{$this->room->code}/state")->assertJsonPath('playback.transition_seconds', 6);
});

it('laisse l\'hôte changer la durée des transitions, même quand rien ne joue, et le diffuse', function () {
    Event::fake([PlaybackStateChanged::class]);

    $this->actingAs($this->host)->putJson("/host/rooms/{$this->room->code}/transition", ['seconds' => 10])
        ->assertOk()
        ->assertJsonPath('playback.transition_seconds', 10)
        ->assertJsonPath('playback.status', 'idle')
        ->assertJsonPath('version', 1);

    Event::assertDispatched(
        PlaybackStateChanged::class,
        fn ($e) => $e->version === 1 && $e->playback['transition_seconds'] === 10,
    );

    // Même valeur : rien ne change, pas de nouvelle version.
    $this->actingAs($this->host)->putJson("/host/rooms/{$this->room->code}/transition", ['seconds' => 10])
        ->assertJsonPath('version', 1);

    $this->actingAs($this->host)->putJson("/host/rooms/{$this->room->code}/transition", ['seconds' => 0])
        ->assertJsonPath('playback.transition_seconds', 0);
});

it('ne modifie pas la position de lecture en changeant la transition', function () {
    $this->freezeTime();
    queueTracks($this->room, 1);
    $this->actingAs($this->host)->postJson($this->url, ['action' => 'play'])->assertOk();
    $this->travel(5)->seconds();

    $this->actingAs($this->host)->putJson("/host/rooms/{$this->room->code}/transition", ['seconds' => 3])
        ->assertJsonPath('playback.position_ms', 0)
        ->assertJsonPath('playback.status', 'playing');

    expect($this->room->fresh()->currentPositionMs())->toBe(5000);
});

it('refuse une durée de transition non proposée', function (mixed $seconds) {
    $this->actingAs($this->host)->putJson("/host/rooms/{$this->room->code}/transition", ['seconds' => $seconds])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('seconds');
})->with([5, -1, 60, 'abc', null]);

it('réserve le réglage des transitions au propriétaire du salon', function () {
    $this->actingAs(User::factory()->create())
        ->putJson("/host/rooms/{$this->room->code}/transition", ['seconds' => 3])
        ->assertForbidden();
});
