// resources/js/__tests__/factories.ts — fabriques de données pour les tests frontend.
import type { PlaybackState, Provider, QueueItem, RoomState, SearchResult, Track } from '@/types';

let nextId = 1;

export function makeTrack(overrides: Partial<Track> = {}): Track {
    const id = overrides.id ?? nextId++;

    return {
        id,
        provider: 'youtube',
        provider_track_id: `track-${id}`,
        title: `Titre ${id}`,
        artist: `Artiste ${id}`,
        album_art_url: null,
        duration_ms: 200_000,
        ...overrides,
    };
}

export function makeItem(overrides: Partial<QueueItem> & { provider?: Provider } = {}): QueueItem {
    const { provider, ...rest } = overrides;
    const id = rest.id ?? nextId++;

    return {
        id,
        position: id,
        status: 'queued',
        track: makeTrack(provider ? { provider } : {}),
        added_by: { type: 'guest', name: 'Léa' },
        ...rest,
    };
}

export function makePlayback(overrides: Partial<PlaybackState> = {}): PlaybackState {
    return { status: 'idle', current: null, position_ms: 0, position_updated_at: null, playlist: null, ...overrides };
}

export function makeRoomState(overrides: Partial<RoomState> = {}): RoomState {
    return {
        code: 'ABCD',
        status: 'open',
        version: 0,
        server_time: Date.now(),
        spotify_enabled: true,
        playback: makePlayback(),
        queue: [],
        ...overrides,
    };
}

export function makeSearchResult(overrides: Partial<SearchResult> = {}): SearchResult {
    const id = nextId++;

    return {
        provider: 'youtube',
        provider_track_id: `result-${id}`,
        title: `Résultat ${id}`,
        artist: `Artiste ${id}`,
        album_art_url: null,
        duration_ms: 180_000,
        ...overrides,
    };
}
