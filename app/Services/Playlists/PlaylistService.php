<?php

namespace App\Services\Playlists;

use App\Data\PlaylistData;
use App\Data\TrackData;
use App\Enums\Provider;
use App\Events\PlaybackStateChanged;
use App\Exceptions\PlaylistException;
use App\Exceptions\ProviderException;
use App\Exceptions\RoomClosedException;
use App\Exceptions\SpotifyAuthException;
use App\Models\Playlist;
use App\Models\Room;
use App\Models\Track;
use App\Models\User;
use App\Services\Music\ProviderRegistry;
use App\Services\Music\SpotifyProvider;
use App\Services\Music\YouTubeProvider;
use App\Services\RoomBroadcaster;
use App\Services\Spotify\SpotifyTokenService;
use Illuminate\Support\Facades\DB;

/**
 * Playlist de fond d'un salon : lecture chez la source, enregistrement, activation, retrait.
 *
 * L'import se fait en deux temps : `fetch()` interroge la source (appels HTTP, hors transaction),
 * puis `attach()` enregistre tout et active la playlist sous le verrou du salon.
 */
class PlaylistService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly SpotifyProvider $spotify,
        private readonly YouTubeProvider $youtube,
        private readonly SpotifyTokenService $tokens,
        private readonly RoomBroadcaster $broadcaster,
    ) {}

    /**
     * Lit la playlist désignée par un lien.
     *
     * @throws PlaylistException Lien non reconnu, source indisponible, playlist introuvable ou vide.
     */
    public function fetch(string $link, User $host): PlaylistData
    {
        $parsed = PlaylistLink::parse($link)
            ?? throw new PlaylistException('Lien non reconnu. Collez le lien de partage d\'une playlist Spotify ou YouTube.');

        if (! $this->registry->isEnabledForHost($parsed->provider, $host)) {
            throw new PlaylistException($parsed->provider === Provider::Spotify
                ? 'Les playlists Spotify nécessitent un compte Spotify Premium pour l\'hôte.'
                : 'YouTube n\'est pas configuré (clé YOUTUBE_API_KEY manquante).');
        }

        $max = (int) config('jukebox.playlist.max_tracks');

        try {
            $data = match ($parsed->provider) {
                Provider::Spotify => $this->spotify->playlist($parsed->id, $this->tokens->freshAccessToken($host), $max),
                Provider::YouTube => $this->youtube->playlist($parsed->id, $max),
            };
        } catch (ProviderException $e) {
            report($e);

            // Le détail technique (code HTTP, étape) est affiché : c'est ce qui permet de diagnostiquer.
            throw new PlaylistException("La source n'a pas répondu correctement : {$e->getMessage()} Réessayez dans un instant.");
        } catch (SpotifyAuthException) {
            throw new PlaylistException('Votre session Spotify a expiré : déconnectez-vous puis reconnectez-vous.');
        }

        if ($data === null) {
            throw new PlaylistException($parsed->provider === Provider::Spotify
                ? 'Playlist introuvable. Vérifiez le lien. Spotify ne donne pas accès à ses playlists éditoriales ou générées (« Daily Mix », « Top 50 »…) : utilisez une playlist créée par vous ou par un autre utilisateur.'
                : 'Playlist introuvable ou privée. Les « mix » générés par YouTube ne sont pas accessibles : utilisez une playlist publique ou non répertoriée.');
        }

        if ($data->tracks === []) {
            throw new PlaylistException('Cette playlist ne contient aucun morceau lisible.');
        }

        return $data;
    }

    /**
     * Enregistre la playlist et en fait la playlist de fond du salon (lecture depuis le début).
     */
    public function attach(Room $room, PlaylistData $data, bool $shuffle): Playlist
    {
        $tracks = $data->tracks;

        if ($shuffle) {
            shuffle($tracks);
        }

        $trackIds = $this->persistTracks($tracks);

        $playlist = DB::transaction(function () use ($room, $data, $shuffle, $trackIds) {
            $locked = $this->lock($room);

            $playlist = Playlist::create([
                'room_id' => $locked->id,
                'provider' => $data->provider,
                'provider_playlist_id' => $data->providerPlaylistId,
                'name' => mb_substr($data->name, 0, 512),
                'image_url' => $data->imageUrl,
                'shuffled' => $shuffle,
            ]);

            DB::table('playlist_tracks')->insert(array_map(
                fn (int $trackId, int $position) => ['playlist_id' => $playlist->id, 'track_id' => $trackId, 'position' => $position],
                $trackIds,
                array_keys($trackIds),
            ));

            $locked->playlist_id = $playlist->id;
            $locked->playlist_position = 0;
            $locked->bumpVersion()->save();

            return $playlist;
        });

        $room->refresh();
        // La playlist fait partie de l'état de lecture : les écrans se mettent à jour via l'event habituel.
        $this->broadcaster->send(PlaybackStateChanged::fromRoom($room));

        return $playlist;
    }

    /** Retire la playlist de fond : quand la file sera vide, la musique s'arrêtera. */
    public function detach(Room $room): void
    {
        $changed = DB::transaction(function () use ($room) {
            $locked = $this->lock($room);

            if ($locked->playlist_id === null) {
                return false;
            }

            $locked->playlist_id = null;
            $locked->playlist_position = 0;
            $locked->bumpVersion()->save();

            return true;
        });

        $room->refresh();

        if ($changed) {
            $this->broadcaster->send(PlaybackStateChanged::fromRoom($room));
        }
    }

    /**
     * Crée ou met à jour les lignes `tracks` en une requête, puis renvoie leurs ids dans l'ordre reçu.
     *
     * @param  list<TrackData>  $tracks
     * @return list<int>
     */
    private function persistTracks(array $tracks): array
    {
        $now = now();
        $rows = [];

        foreach ($tracks as $track) {
            // Clé par identifiant : une playlist peut contenir deux fois le même morceau, l'upsert ne le veut qu'une fois.
            $rows[$track->provider->value.':'.$track->providerTrackId] = [
                'provider' => $track->provider->value,
                'provider_track_id' => $track->providerTrackId,
                'title' => mb_substr($track->title, 0, 512),
                'artist' => mb_substr($track->artist, 0, 512),
                'album_art_url' => $track->albumArtUrl,
                'duration_ms' => $track->durationMs,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            Track::query()->upsert($chunk, ['provider', 'provider_track_id'], ['title', 'artist', 'album_art_url', 'duration_ms', 'updated_at']);
        }

        // Relecture des ids, source par source.
        $byKey = [];

        foreach (collect($tracks)->groupBy(fn (TrackData $t) => $t->provider->value) as $provider => $group) {
            Track::query()
                ->where('provider', $provider)
                ->whereIn('provider_track_id', $group->map(fn (TrackData $t) => $t->providerTrackId)->unique()->values())
                ->get(['id', 'provider', 'provider_track_id'])
                ->each(function (Track $t) use (&$byKey) {
                    $byKey[$t->provider->value.':'.$t->provider_track_id] = $t->id;
                });
        }

        // Une entrée par morceau de la playlist (doublons compris), dans l'ordre.
        return array_values(array_map(
            fn (TrackData $t) => $byKey[$t->provider->value.':'.$t->providerTrackId],
            $tracks,
        ));
    }

    private function lock(Room $room): Room
    {
        $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw new RoomClosedException;
        }

        return $locked;
    }
}
