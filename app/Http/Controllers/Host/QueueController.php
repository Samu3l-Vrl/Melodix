<?php

namespace App\Http\Controllers\Host;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdvanceQueueRequest;
use App\Http\Requests\ReorderQueueRequest;
use App\Http\Resources\RoomStateResource;
use App\Models\Room;
use App\Services\QueueService;
use Illuminate\Support\Facades\Gate;

/**
 * Gestion de la file par l'hôte : passer, retirer, réordonner.
 * Chaque action renvoie l'état complet du salon.
 */
class QueueController extends Controller
{
    public function __construct(private readonly QueueService $queue) {}

    /** POST /host/rooms/{code}/queue/advance — skip (ou saut automatique après une erreur de lecture). */
    public function advance(AdvanceQueueRequest $request, Room $room): RoomStateResource
    {
        $this->queue->advance($room, $request->expectedItemId(), QueueItemStatus::Skipped);

        return RoomStateResource::for($room);
    }

    /** PUT /host/rooms/{code}/queue/reorder — nouvel ordre complet des morceaux en attente. */
    public function reorder(ReorderQueueRequest $request, Room $room): RoomStateResource
    {
        $this->queue->reorder($room, $request->order());

        return RoomStateResource::for($room);
    }

    /** DELETE /host/rooms/{code}/queue/{item} — retire un morceau en attente. */
    public function destroy(Room $room, int $item): RoomStateResource
    {
        Gate::authorize('control', $room);

        $this->queue->remove($room, $item);

        return RoomStateResource::for($room);
    }
}
