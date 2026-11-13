// resources/js/types/index.ts — types partagés, alignés un pour un sur les API Resources PHP.
// Toute modification d'une Resource doit être répercutée ici (et inversement).

/** App\Enums\Provider */
export type Provider = 'spotify' | 'youtube';

/** App\Enums\PlaybackStatus */
export type PlaybackStatus = 'idle' | 'playing' | 'paused';

/** App\Enums\QueueItemStatus */
export type QueueItemStatus = 'queued' | 'playing' | 'played' | 'skipped';

/** App\Enums\RoomStatus */
export type RoomStatus = 'open' | 'closed';

/** App\Http\Resources\TrackResource */
export interface Track {
    id: number;
    provider: Provider;
    provider_track_id: string;
    title: string;
    artist: string;
    album_art_url: string | null;
    duration_ms: number;
}

/** App\Http\Resources\SearchResultResource — un morceau pas encore persistant, donc sans id. */
export type SearchResult = Omit<Track, 'id'>;

/** Auteur d'un ajout (QueueItemResource::author). Pour une playlist, `name` est le nom de la playlist. */
export interface AddedBy {
    type: 'guest' | 'host' | 'playlist';
    name: string;
}

/** App\Http\Resources\PlaylistResource — playlist de fond, jouée quand la file est vide. */
export interface PlaylistSummary {
    id: number;
    provider: Provider;
    name: string;
    image_url: string | null;
    shuffled: boolean;
}

/** Une playlist du compte Spotify de l'hôte (GET /host/spotify/playlists). */
export interface SpotifyPlaylistOption {
    id: string;
    name: string;
    image_url: string | null;
    track_count: number | null;
    owner: string | null;
}

/** App\Http\Resources\QueueItemResource */
export interface QueueItem {
    id: number;
    position: number;
    status: QueueItemStatus;
    track: Track;
    added_by: AddedBy;
}

/**
 * App\Http\Resources\PlaybackStateResource
 * Point de référence temporel : "à l'instant position_updated_at (ms epoch, horloge serveur),
 * la lecture était à position_ms". La position courante s'en déduit (voir lib/time.ts).
 */
export interface PlaybackState {
    status: PlaybackStatus;
    current: QueueItem | null;
    position_ms: number;
    position_updated_at: number | null;
    /** Playlist de fond du salon, ou null. */
    playlist: PlaylistSummary | null;
}

/** App\Http\Resources\RoomStateResource — état complet d'un salon. */
export interface RoomState {
    code: string;
    status: RoomStatus;
    /** Compteur monotone : +1 à chaque mutation côté serveur. */
    version: number;
    /** Horloge serveur (ms epoch) au moment de la sérialisation. */
    server_time: number;
    spotify_enabled: boolean;
    playback: PlaybackState;
    /** Morceaux en attente, dans l'ordre de passage. Le morceau en cours n'y figure pas. */
    queue: QueueItem[];
}

/** Champs communs à tous les events versionnés. */
interface VersionedEvent {
    version: number;
    server_time: number;
}

/** App\Events\QueueUpdated — delta de file (union discriminée sur `action`). */
export type QueueUpdatedEvent = VersionedEvent &
    (
        | { action: 'added'; item: QueueItem; item_id: null; order: null }
        | { action: 'removed'; item: null; item_id: number; order: null }
        | { action: 'reordered'; item: null; item_id: null; order: number[] }
    );

/** App\Events\PlaybackStateChanged */
export interface PlaybackStateChangedEvent extends VersionedEvent {
    playback: PlaybackState;
}

/** App\Events\GuestJoined — canal privé de l'hôte, hors état versionné. */
export interface GuestJoinedEvent {
    guest: Guest;
    guests_count: number;
}

/** App\Http\Resources\GuestResource */
export interface Guest {
    id: number;
    nickname: string;
}

/** Réponse de POST /api/rooms/{code}/guests */
export interface JoinResponse {
    guest: Guest;
    token: string;
}

/** App\Http\Resources\HostResource */
export interface HostUser {
    id: number;
    name: string;
    avatar: string | null;
    spotify_premium: boolean;
}

/** État d'une source dans une réponse de recherche (App\Data\SearchOutcome). */
export type SourceStatus = 'ok' | 'error' | 'disabled';

/** Réponse de GET /api/rooms/{code}/search */
export interface SearchResponse {
    results: SearchResult[];
    sources: Partial<Record<Provider, SourceStatus>>;
}

/** Réponse de GET /host/spotify/token */
export interface SpotifyTokenResponse {
    access_token: string;
    expires_at: number | null;
}

/** Corps de POST /host/rooms/{code}/playback (App\Http\Requests\PlaybackRequest). */
export type PlaybackCommand =
    | { action: 'play'; position_ms?: number }
    | { action: 'pause'; position_ms?: number }
    | { action: 'seek'; position_ms: number }
    | { action: 'ended'; item_id: number };

/** État de la connexion temps réel, tel qu'affiché à l'utilisateur. */
export type ConnectionState = 'connected' | 'reconnecting' | 'offline';

/** Props partagées par toutes les pages (HandleInertiaRequests::share). */
export interface SharedProps {
    app: { name: string };
    auth: { user: HostUser | null };
    flash: { error: string | null };
    // Signature d'index exigée par le type PageProps d'Inertia.
    [key: string]: unknown;
}
