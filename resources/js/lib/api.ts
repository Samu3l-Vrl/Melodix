// resources/js/lib/api.ts — client HTTP typé pour tous les endpoints JSON (invité et hôte).
// Basé sur fetch : pas de dépendance, et chaque appel renvoie directement le type attendu.
import type {
    Guest,
    JoinResponse,
    PlaybackCommand,
    Provider,
    RoomState,
    SearchResponse,
    SpotifyPlaylistOption,
    SpotifyTokenResponse,
} from '@/types';

/** Erreur HTTP normalisée : statut + code métier éventuel renvoyé par le serveur (JukeboxException). */
export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        /** Ex. "duplicate_track", "queue_conflict", "room_closed", "seek_throttled"… */
        public readonly code: string | null = null,
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

interface RequestOptions {
    body?: unknown;
    /** Jeton invité, envoyé dans X-Guest-Token. */
    guestToken?: string;
    /** Permet d'annuler une requête devenue inutile (recherche remplacée par une plus récente). */
    signal?: AbortSignal;
}

type Method = 'GET' | 'POST' | 'PUT' | 'DELETE';

/**
 * Lit le cookie XSRF-TOKEN posé par Laravel. Renvoyé dans l'en-tête X-XSRF-TOKEN, il prouve que
 * la requête vient bien de notre page (protection CSRF des routes "web" de l'hôte).
 */
function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

async function request<T>(method: Method, url: string, options: RequestOptions = {}): Promise<T> {
    const headers: Record<string, string> = {
        // Sans "Accept: application/json", Laravel répondrait aux erreurs par une redirection HTML.
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    if (options.guestToken) {
        headers['X-Guest-Token'] = options.guestToken;
    }

    const xsrf = xsrfToken();

    if (xsrf && method !== 'GET') {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    let response: Response;

    try {
        response = await fetch(url, {
            method,
            headers,
            body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
            // Envoie le cookie de session (hôte) ; sans effet pour les routes /api sans état.
            credentials: 'same-origin',
            signal: options.signal,
        });
    } catch (error) {
        // Annulation volontaire : on laisse remonter l'AbortError telle quelle pour que l'appelant l'ignore.
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        throw new ApiError('Réseau indisponible.', 0, 'network_error');
    }

    const payload: unknown = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        const data = (payload ?? {}) as { message?: unknown; code?: unknown };

        throw new ApiError(
            typeof data.message === 'string' ? data.message : `Erreur ${response.status}`,
            response.status,
            typeof data.code === 'string' ? data.code : null,
        );
    }

    return payload as T;
}

/** Vrai si l'erreur est l'annulation volontaire d'une requête (à ignorer silencieusement). */
export function isAbortError(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
}

const room = (code: string): string => `/api/rooms/${encodeURIComponent(code)}`;
const host = (code: string): string => `/host/rooms/${encodeURIComponent(code)}`;

export const api = {
    // --- Commun -----------------------------------------------------------------
    /** État complet + version : appelé pour resynchroniser. */
    fetchRoomState: (code: string): Promise<RoomState> => request('GET', `${room(code)}/state`),

    // --- Invité -----------------------------------------------------------------
    joinRoom: (code: string, nickname: string): Promise<JoinResponse> =>
        request('POST', `${room(code)}/guests`, { body: { nickname } }),

    /** Vérifie qu'un jeton stocké est toujours valide (reprise de session). */
    me: (code: string, guestToken: string): Promise<{ guest: Guest }> =>
        request('GET', `${room(code)}/guests/me`, { guestToken }),

    search: (code: string, guestToken: string, query: string, signal?: AbortSignal): Promise<SearchResponse> =>
        request('GET', `${room(code)}/search?q=${encodeURIComponent(query)}`, { guestToken, signal }),

    /** Seuls la source et l'identifiant partent : le serveur retrouve lui-même titre, artiste, durée. */
    addToQueue: (code: string, guestToken: string, provider: Provider, providerTrackId: string): Promise<RoomState> =>
        request('POST', `${room(code)}/queue`, {
            guestToken,
            body: { provider, provider_track_id: providerTrackId },
        }),

    // --- Hôte (session + CSRF) -----------------------------------------------------
    playback: (code: string, command: PlaybackCommand): Promise<RoomState> =>
        request('POST', `${host(code)}/playback`, { body: command }),

    /** Skip. `itemId` = le morceau que l'hôte voit en cours (null si aucun) : rend l'appel idempotent. */
    skip: (code: string, itemId: number | null): Promise<RoomState> =>
        request('POST', `${host(code)}/queue/advance`, { body: { item_id: itemId } }),

    removeFromQueue: (code: string, itemId: number): Promise<RoomState> =>
        request('DELETE', `${host(code)}/queue/${itemId}`),

    reorderQueue: (code: string, order: number[]): Promise<RoomState> =>
        request('PUT', `${host(code)}/queue/reorder`, { body: { order } }),

    /** Remplace la playlist de fond (lien Spotify ou YouTube). */
    setPlaylist: (code: string, playlistUrl: string, shuffle: boolean): Promise<RoomState> =>
        request('PUT', `${host(code)}/playlist`, { body: { playlist_url: playlistUrl, shuffle } }),

    /** Retire la playlist de fond. */
    removePlaylist: (code: string): Promise<RoomState> => request('DELETE', `${host(code)}/playlist`),

    /** Playlists du compte Spotify de l'hôte (vide si l'hôte n'est pas Premium). */
    spotifyPlaylists: (): Promise<{ playlists: SpotifyPlaylistOption[] }> => request('GET', '/host/spotify/playlists'),

    /** Jeton Spotify de l'hôte pour le Web Playback SDK (rafraîchi côté serveur si besoin). */
    spotifyToken: (): Promise<SpotifyTokenResponse> => request('GET', '/host/spotify/token'),
};

export type Api = typeof api;
