<?php

namespace App\Http\Controllers;

use App\Exceptions\PlaylistException;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Resources\HostResource;
use App\Http\Resources\RoomStateResource;
use App\Models\Room;
use App\Services\Playlists\PlaylistService;
use App\Services\RoomService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Création d'un salon et pages Inertia (écran hôte, télécommande invité).
 * Les pages reçoivent l'état complet en props : pas d'appel réseau supplémentaire au premier rendu.
 */
class RoomController extends Controller
{
    /**
     * POST /rooms — crée un salon pour l'hôte connecté puis ouvre son écran hôte.
     * Avec `playlist_url`, la playlist est lue AVANT de créer le salon : si elle est inutilisable,
     * aucun salon n'est créé et le formulaire affiche l'erreur.
     */
    public function store(StoreRoomRequest $request, RoomService $rooms, PlaylistService $playlists): RedirectResponse
    {
        $link = $request->validated('playlist_url');
        $data = null;

        if (is_string($link) && $link !== '') {
            try {
                $data = $playlists->fetch($link, $request->user());
            } catch (PlaylistException $e) {
                // Inertia transmet les erreurs de validation au formulaire (props.errors.playlist_url).
                throw ValidationException::withMessages(['playlist_url' => $e->getMessage()]);
            }
        }

        $room = $rooms->create($request->user());

        if ($data !== null) {
            $playlists->attach($room, $data, $request->boolean('shuffle'));
        }

        return redirect()->route('rooms.host', $room);
    }

    /** GET /room/{code}/host — écran TV/PC, réservé au propriétaire. */
    public function host(Request $request, Room $room): Response
    {
        Gate::authorize('control', $room);

        return Inertia::render('Host/HostView', [
            'room' => RoomStateResource::plain($room),
            'host' => (new HostResource($request->user()))->resolve($request),
            'join_url' => $this->joinUrl($room),
        ]);
    }

    /** GET /room/{code}/join — télécommande mobile, publique (le code fait office d'invitation). */
    public function join(Room $room): Response
    {
        return Inertia::render('Guest/GuestView', [
            'room' => RoomStateResource::plain($room),
        ]);
    }

    /** POST /host/rooms/{code}/close — ferme le salon (fin de soirée). */
    public function close(Room $room, RoomService $rooms): RedirectResponse
    {
        Gate::authorize('control', $room);

        $rooms->close($room);

        return redirect()->route('home');
    }

    /**
     * URL encodée dans le QR code. Elle part de JUKEBOX_PUBLIC_URL et non de l'URL courante :
     * l'hôte ouvre souvent l'app en http://127.0.0.1 (imposé par Spotify pour l'OAuth en local),
     * adresse que les téléphones des invités ne peuvent évidemment pas joindre.
     */
    private function joinUrl(Room $room): string
    {
        $base = rtrim((string) (config('jukebox.public_url') ?: config('app.url')), '/');

        return $base.route('rooms.join', $room, absolute: false);
    }
}
