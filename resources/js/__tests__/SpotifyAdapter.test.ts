// resources/js/__tests__/SpotifyAdapter.test.ts — lancement d'un morceau Spotify et détection de sa fin.
// Le Web Playback SDK est remplacé par un faux lecteur : on pilote ses événements à la main.
import { PlayerError } from '@/player/PlayerAdapter';
import { SpotifyAdapter } from '@/player/SpotifyAdapter';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { makeTrack } from './factories';

type StateListener = (state: Spotify.WebPlaybackState | null) => void;

/** Faux lecteur du SDK : émet `ready` à la connexion, et laisse le test émettre des états. */
class FakeSdkPlayer {
    static last: FakeSdkPlayer | null = null;
    private listeners = new Map<string, (arg: never) => void>();
    current: Spotify.WebPlaybackState | null = null;

    constructor() {
        FakeSdkPlayer.last = this;
    }

    addListener(event: string, callback: (arg: never) => void): boolean {
        this.listeners.set(event, callback);

        return true;
    }

    removeListener(): boolean {
        return true;
    }

    async connect(): Promise<boolean> {
        queueMicrotask(() => (this.listeners.get('ready') as ((i: { device_id: string }) => void) | undefined)?.({ device_id: 'device-1' }));

        return true;
    }

    disconnect(): void {}

    async getCurrentState(): Promise<Spotify.WebPlaybackState | null> {
        return this.current;
    }

    async pause(): Promise<void> {}
    async resume(): Promise<void> {}
    async seek(): Promise<void> {}
    async activateElement(): Promise<void> {}

    emit(state: Spotify.WebPlaybackState): void {
        this.current = state;
        (this.listeners.get('player_state_changed') as StateListener | undefined)?.(state);
    }
}

const track = makeTrack({ provider: 'spotify', provider_track_id: 'abc', duration_ms: 200_000 });
const uri = 'spotify:track:abc';

const state = (paused: boolean, position: number): Spotify.WebPlaybackState => ({
    paused,
    position,
    duration: 200_000,
    track_window: {
        current_track: { id: 'abc', uri, name: 'Titre', duration_ms: 200_000 },
        previous_tracks: [],
        next_tracks: [],
    },
});

const response = (status: number): Response => new Response(status === 204 ? null : '{}', { status });

let fetchMock: ReturnType<typeof vi.fn<typeof fetch>>;
let adapter: SpotifyAdapter;

beforeEach(() => {
    vi.useFakeTimers();
    window.Spotify = { Player: FakeSdkPlayer } as unknown as typeof Spotify;
    fetchMock = vi.fn<typeof fetch>();
    vi.stubGlobal('fetch', fetchMock);
    adapter = new SpotifyAdapter({ getToken: async () => 'token', deviceName: 'Jukebox TEST' });
});

afterEach(() => {
    adapter.destroy();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

/** Lance load() et fait avancer les minuteries jusqu'à ce que la promesse soit réglée. */
async function runLoad(): Promise<unknown> {
    const outcome = adapter.load(track, { positionMs: 0, autoplay: true }).then(
        () => 'ok',
        (error: unknown) => error,
    );
    // Durée fixe (et non runAllTimers) : la relève de position toutes les 500 ms ne s'arrête jamais.
    await vi.advanceTimersByTimeAsync(20_000);

    return outcome;
}

describe('SpotifyAdapter — lancement du morceau', () => {
    it('réessaie tant que Spotify ne trouve pas encore l\'appareil (404), puis lance le morceau', async () => {
        fetchMock.mockResolvedValueOnce(response(404)).mockResolvedValueOnce(response(404)).mockResolvedValueOnce(response(204));

        expect(await runLoad()).toBe('ok');
        expect(fetchMock).toHaveBeenCalledTimes(3);

        const [url, init] = fetchMock.mock.calls[0] ?? [];
        expect(url).toBe('https://api.spotify.com/v1/me/player/play?device_id=device-1');
        expect(JSON.parse(String(init?.body))).toEqual({ uris: [uri], position_ms: 0 });
    });

    it('abandonne après plusieurs tentatives avec un message explicite, sans parler de morceau illisible', async () => {
        fetchMock.mockResolvedValue(response(404));

        const error = await runLoad();

        expect(fetchMock).toHaveBeenCalledTimes(5);
        expect(error).toBeInstanceOf(PlayerError);
        expect((error as PlayerError).code).toBe('init');
        expect((error as PlayerError).message).toContain('ne trouve pas le lecteur');
    });

    it('ne réessaie pas un refus définitif (403 : compte non Premium)', async () => {
        fetchMock.mockResolvedValue(response(403));

        const error = await runLoad();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect((error as PlayerError).code).toBe('auth');
    });

    it('déclare l\'onglet comme appareil actif au démarrage de la session', async () => {
        fetchMock.mockResolvedValue(response(204));

        const prepared = adapter.prepare();
        await vi.advanceTimersByTimeAsync(100);
        await prepared;

        const [url, init] = fetchMock.mock.calls[0] ?? [];
        expect(url).toBe('https://api.spotify.com/v1/me/player');
        expect(JSON.parse(String(init?.body))).toEqual({ device_ids: ['device-1'], play: false });
    });
});

describe('SpotifyAdapter — détection de la fin du morceau', () => {
    it('ne prend pas un état « en pause à 0 » de début de lecture pour une fin', async () => {
        fetchMock.mockResolvedValue(response(204));
        const ended = vi.fn();
        adapter.onEnded(ended);
        await runLoad();
        const sdk = FakeSdkPlayer.last!;

        // Le morceau démarre, puis le SDK repasse brièvement « en pause à 0 » (mise en mémoire tampon).
        sdk.emit(state(false, 0));
        sdk.emit(state(false, 800));
        sdk.emit(state(true, 0));
        // Variante : le SDK liste aussi le morceau courant dans `previous_tracks`. L'ancienne détection
        // y voyait une fin et sautait le morceau au bout d'une seconde.
        const listed = state(true, 0);
        listed.track_window.previous_tracks = [listed.track_window.current_track];
        sdk.emit(listed);

        expect(ended).not.toHaveBeenCalled();
    });

    it('signale la fin quand le morceau est allé jusqu\'au bout', async () => {
        fetchMock.mockResolvedValue(response(204));
        const ended = vi.fn();
        adapter.onEnded(ended);
        await runLoad();
        const sdk = FakeSdkPlayer.last!;

        sdk.emit(state(false, 1_000));
        // La position est relevée toutes les 500 ms, même sans event du SDK.
        sdk.current = state(false, 199_000);
        await vi.advanceTimersByTimeAsync(600);
        sdk.emit(state(true, 0));
        sdk.emit(state(true, 0));

        expect(ended).toHaveBeenCalledTimes(1);
    });

    it('ignore les états qui concernent un autre morceau', async () => {
        fetchMock.mockResolvedValue(response(204));
        const ended = vi.fn();
        adapter.onEnded(ended);
        await runLoad();
        const sdk = FakeSdkPlayer.last!;
        const other = (paused: boolean, position: number): Spotify.WebPlaybackState => ({
            ...state(paused, position),
            track_window: { current_track: { id: 'zzz', uri: 'spotify:track:zzz', name: 'Autre', duration_ms: 200_000 }, previous_tracks: [], next_tracks: [] },
        });

        sdk.emit(other(false, 199_500));
        sdk.emit(other(true, 0));

        expect(ended).not.toHaveBeenCalled();
    });
});
