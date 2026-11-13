<?php

// tests/Feature/BroadcastingTest.php — events diffusés : classe, canal, payload et version croissante.

use App\Enums\QueueItemStatus;
use App\Events\PlaybackStateChanged;
use App\Events\QueueUpdated;
use App\Models\Track;
use App\Models\User;
use App\Services\PlaybackService;
use App\Services\QueueService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([QueueUpdated::class, PlaybackStateChanged::class]);

    $this->host = User::factory()->create();
    $this->room = makeRoom($this->host);
    $this->queue = app(QueueService::class);
});

it('diffuse QueueUpdated(added) sur le canal public du salon', function () {
    $this->freezeTime();
    $guest = makeGuest($this->room, 'Léa');
    $item = $this->queue->add($this->room, Track::factory()->create(['title' => 'Around the World']), $guest);

    Event::assertDispatched(QueueUpdated::class, function (QueueUpdated $event) use ($item) {
        $channel = $event->broadcastOn()[0];
        $payload = $event->broadcastWith();

        expect($event)->toBeInstanceOf(ShouldBroadcastNow::class)
            ->and($channel)->toBeInstanceOf(Channel::class)
            ->and($channel)->not->toBeInstanceOf(PrivateChannel::class) // canal PUBLIC
            ->and($channel->name)->toBe("room.{$this->room->code}")
            ->and($event->broadcastAs())->toBe('queue.updated')
            ->and($payload['version'])->toBe(1)
            ->and($payload['server_time'])->toBe(now()->getTimestampMs())
            ->and($payload['action'])->toBe('added')
            ->and($payload['item']['id'])->toBe($item->id)
            ->and($payload['item']['track']['title'])->toBe('Around the World')
            ->and($payload['item']['added_by'])->toBe(['type' => 'guest', 'name' => 'Léa']);

        return true;
    });
});

it('diffuse les deltas removed et reordered', function () {
    [$a, $b, $c] = queueTracks($this->room, 3);

    $this->queue->reorder($this->room, [$c->id, $a->id, $b->id]);
    $this->queue->remove($this->room, $a->id);

    Event::assertDispatched(QueueUpdated::class, fn (QueueUpdated $e) => $e->action === 'reordered'
        && $e->broadcastWith()['order'] === [$c->id, $a->id, $b->id]
        && $e->version === 4);

    Event::assertDispatched(QueueUpdated::class, fn (QueueUpdated $e) => $e->action === 'removed'
        && $e->broadcastWith()['item_id'] === $a->id
        && $e->version === 5);
});

it('diffuse PlaybackStateChanged avec le morceau courant et le point de référence temporel', function () {
    $this->freezeTime();
    [$first] = queueTracks($this->room, 1);

    $this->queue->advance($this->room, null, QueueItemStatus::Played);

    Event::assertDispatched(PlaybackStateChanged::class, function (PlaybackStateChanged $event) use ($first) {
        $payload = $event->broadcastWith();

        expect($event)->toBeInstanceOf(ShouldBroadcastNow::class)
            ->and($event->broadcastOn()[0]->name)->toBe("room.{$this->room->code}")
            ->and($event->broadcastAs())->toBe('playback.changed')
            ->and($payload['version'])->toBe(2)
            ->and($payload['server_time'])->toBe(now()->getTimestampMs())
            ->and($payload['playback']['status'])->toBe('playing')
            ->and($payload['playback']['current']['id'])->toBe($first->id)
            ->and($payload['playback']['position_ms'])->toBe(0)
            ->and($payload['playback']['position_updated_at'])->toBe(now()->getTimestampMs());

        return true;
    });
});

it('émet exactement un event par mutation, avec des versions strictement croissantes et sans trou', function () {
    $playback = app(PlaybackService::class);

    [$a, $b, $c] = queueTracks($this->room, 3);             // v1, v2, v3
    $playback->play($this->room);                            // v4 (démarre a)
    $playback->pause($this->room);                           // v5
    $playback->seek($this->room, 30_000);                    // v6
    $playback->play($this->room);                            // v7
    $this->queue->reorder($this->room, [$c->id, $b->id]);    // v8
    $this->queue->remove($this->room, $b->id);               // v9
    $this->queue->advance($this->room, $a->id, QueueItemStatus::Skipped); // v10 (démarre c)
    $this->queue->advance($this->room, $c->id, QueueItemStatus::Played);  // v11 (arrêt)

    $recorded = collect(Event::dispatched(QueueUpdated::class))
        ->merge(Event::dispatched(PlaybackStateChanged::class))
        ->map(fn (array $args) => $args[0]->version)
        ->sort()
        ->values()
        ->all();

    expect($recorded)->toBe(range(1, 11))
        ->and($this->room->fresh()->version)->toBe(11);

    Event::assertDispatchedTimes(QueueUpdated::class, 5);
    Event::assertDispatchedTimes(PlaybackStateChanged::class, 6);
});

it('ne diffuse rien quand une action échoue ou ne change rien', function () {
    $guest = makeGuest($this->room);
    $track = Track::factory()->create();
    $item = $this->queue->add($this->room, $track, $guest);

    // Doublon refusé.
    try {
        $this->queue->add($this->room, $track, $guest);
    } catch (Throwable) {
        // attendu
    }

    // advance périmé (mauvais morceau attendu) et pause sans lecture en cours.
    $this->queue->advance($this->room, 999_999, QueueItemStatus::Played);
    app(PlaybackService::class)->pause($this->room);

    Event::assertDispatchedTimes(QueueUpdated::class, 1);
    Event::assertNotDispatched(PlaybackStateChanged::class);
    expect($this->room->fresh()->version)->toBe(1)
        ->and($item->fresh()->status)->toBe(QueueItemStatus::Queued);
});
