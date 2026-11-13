<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TrackUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddToQueueRequest;
use App\Http\Resources\RoomStateResource;
use App\Models\Guest;
use App\Models\Room;
use App\Services\Music\ProviderRegistry;
use App\Services\Music\TrackResolver;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/rooms/{code}/queue — un invité ajoute un morceau à la file commune.
 */
class QueueController extends Controller
{
    public function store(
        AddToQueueRequest $request,
        Room $room,
        ProviderRegistry $registry,
        TrackResolver $resolver,
        QueueService $queue,
    ): JsonResponse {
        /** @var Guest $guest */
        $guest = $request->attributes->get('guest');
        $provider = $request->provider();

        // La recherche filtre déjà les sources désactivées, mais une requête forgée pourrait
        // quand même tenter d'ajouter un morceau Spotify dans un salon sans hôte Premium.
        if (! $registry->isEnabledFor($provider, $room)) {
            throw TrackUnavailableException::providerDisabled($provider->label());
        }

        $track = $resolver->resolve($provider, $request->validated('provider_track_id'));

        // Lève DuplicateTrackException (409) si le morceau est déjà en file.
        $queue->add($room, $track, $guest);

        // On renvoie l'état complet : le client l'applique tout de suite, sans attendre l'event.
        return RoomStateResource::for($room)->response()->setStatusCode(201);
    }
}
