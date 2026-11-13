<?php

namespace App\Http\Controllers\Host;

use App\Exceptions\ProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetPlaylistRequest;
use App\Http\Resources\RoomStateResource;
use App\Models\Room;
use App\Services\Music\SpotifyProvider;
use App\Services\Playlists\PlaylistService;
use App\Services\Spotify\SpotifyTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Playlist de fond : liste des playlists Spotify de l'hôte, choix, retrait.
 */
class PlaylistController extends Controller
{
    /**
     * GET /host/spotify/playlists — playlists du compte Spotify de l'hôte, pour le sélecteur.
     * Vide (et non une erreur) si l'hôte n'est pas Premium : ses playlists ne seraient pas lisibles.
     */
    public function index(Request $request, SpotifyProvider $spotify, SpotifyTokenService $tokens): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasSpotifyPremium() || ! $spotify->isConfigured()) {
            return response()->json(['playlists' => []]);
        }

        try {
            $playlists = $spotify->userPlaylists($tokens->freshAccessToken($user));
        } catch (ProviderException $e) {
            report($e);

            return response()->json([
                'message' => 'Impossible de lire vos playlists Spotify. Si vous venez de mettre à jour l\'application, déconnectez-vous puis reconnectez-vous.',
                'code' => 'spotify_playlists_unavailable',
            ], 502);
        }

        return response()->json(['playlists' => $playlists]);
    }

    /** PUT /host/rooms/{code}/playlist — remplace la playlist de fond (reprise au début). */
    public function update(SetPlaylistRequest $request, Room $room, PlaylistService $playlists): RoomStateResource
    {
        // PlaylistException (422) est rendue en JSON avec son message, affiché tel quel par l'écran hôte.
        $data = $playlists->fetch($request->validated('playlist_url'), $request->user());

        $playlists->attach($room, $data, $request->boolean('shuffle'));

        return RoomStateResource::for($room);
    }

    /** DELETE /host/rooms/{code}/playlist — plus de musique de fond quand la file est vide. */
    public function destroy(Room $room, PlaylistService $playlists): RoomStateResource
    {
        Gate::authorize('control', $room);

        $playlists->detach($room);

        return RoomStateResource::for($room);
    }
}
