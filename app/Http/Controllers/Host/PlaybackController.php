<?php

namespace App\Http\Controllers\Host;

use App\Enums\QueueItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaybackRequest;
use App\Http\Resources\RoomStateResource;
use App\Models\Room;
use App\Services\PlaybackService;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /host/rooms/{code}/playback — l'écran hôte signale play / pause / seek / ended.
 * Le serveur valide, persiste, incrémente `version` et diffuse ; il renvoie l'état complet.
 */
class PlaybackController extends Controller
{
    public function __invoke(
        PlaybackRequest $request,
        Room $room,
        PlaybackService $playback,
        QueueService $queue,
    ): RoomStateResource|JsonResponse {
        $position = $request->positionMs();

        switch ($request->validated('action')) {
            case 'play':
                $playback->play($room, $position);
                break;

            case 'pause':
                $playback->pause($room, $position);
                break;

            case 'seek':
                // Au plus une mise à jour de position par seconde et par salon : glisser le curseur
                // ou corriger une dérive ne doit pas produire une rafale d'écritures et d'events.
                $throttleKey = "playback-seek:{$room->id}";

                if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 1)) {
                    return response()->json([
                        'message' => 'Position mise à jour trop souvent.',
                        'code' => 'seek_throttled',
                    ], 429, ['Retry-After' => '1']);
                }

                // La "fenêtre" d'une seconde démarre maintenant.
                RateLimiter::hit($throttleKey, decaySeconds: 1);
                $playback->seek($room, (int) $position);
                break;

            case 'ended':
                // `item_id` = le morceau qui vient de finir. Si le salon est déjà passé au suivant
                // (double event du lecteur, deuxième onglet hôte), advance() ne fait rien.
                $queue->advance($room, $request->integer('item_id'), QueueItemStatus::Played);
                break;
        }

        return RoomStateResource::for($room);
    }
}
