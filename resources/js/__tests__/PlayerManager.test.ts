// resources/js/__tests__/PlayerManager.test.ts — choix de l'adaptateur par source, bascule et saut automatique sur erreur.
import {
    Emitter,
    PlayerError,
    type LoadOptions,
    type PlayerAdapter,
    type ProgressListener,
    type Unsubscribe,
} from '@/player/PlayerAdapter';
import { PlayerManager, type PlayerManagerEvents } from '@/player/PlayerManager';
import type { Provider, Track } from '@/types';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { makeTrack } from './factories';

/** Faux lecteur : enregistre les appels reçus et permet d'émettre fin / erreur / progression à la demande. */
class FakeAdapter implements PlayerAdapter {
    readonly calls: string[] = [];
    loadError: Error | null = null;
    playError: Error | null = null;

    private readonly progress = new Emitter<[number, number]>();
    private readonly ended = new Emitter<[]>();
    private readonly errors = new Emitter<[PlayerError]>();

    constructor(readonly provider: Provider) {}

    async prepare(): Promise<void> {
        this.calls.push('prepare');
    }

    async load(track: Track, options: LoadOptions): Promise<void> {
        this.calls.push(`load:${track.provider_track_id}@${options.positionMs}:${options.autoplay ? 'play' : 'cue'}`);

        if (this.loadError) {
            throw this.loadError;
        }
    }

    async play(): Promise<void> {
        this.calls.push('play');

        if (this.playError) {
            throw this.playError;
        }
    }

    async pause(): Promise<void> {
        this.calls.push('pause');
    }

    async seek(positionMs: number): Promise<void> {
        this.calls.push(`seek:${positionMs}`);
    }

    destroy(): void {
        this.calls.push('destroy');
    }

    onProgress(listener: ProgressListener): Unsubscribe {
        return this.progress.subscribe(listener);
    }

    onEnded(listener: () => void): Unsubscribe {
        return this.ended.subscribe(listener);
    }

    onError(listener: (error: PlayerError) => void): Unsubscribe {
        return this.errors.subscribe(listener);
    }

    emitEnded(): void {
        this.ended.emit();
    }

    emitError(error: PlayerError): void {
        this.errors.emit(error);
    }

    emitProgress(positionMs: number, durationMs: number): void {
        this.progress.emit(positionMs, durationMs);
    }
}

const spotifyTrack = makeTrack({ provider: 'spotify', provider_track_id: 'sp1' });
const youtubeTrack = makeTrack({ provider: 'youtube', provider_track_id: 'yt1' });
const playing: LoadOptions = { positionMs: 0, autoplay: true };

let spotify: FakeAdapter;
let youtube: FakeAdapter;
let events: { [K in keyof Required<PlayerManagerEvents>]: ReturnType<typeof vi.fn<Required<PlayerManagerEvents>[K]>> };
let spotifyFactory: ReturnType<typeof vi.fn<() => PlayerAdapter>>;
let youtubeFactory: ReturnType<typeof vi.fn<() => PlayerAdapter>>;

beforeEach(() => {
    spotify = new FakeAdapter('spotify');
    youtube = new FakeAdapter('youtube');
    spotifyFactory = vi.fn(() => spotify);
    youtubeFactory = vi.fn(() => youtube);
    events = { onTrackEnded: vi.fn(), onTrackError: vi.fn(), onProgress: vi.fn() };
});

const makeManager = (): PlayerManager => new PlayerManager({ spotify: spotifyFactory, youtube: youtubeFactory }, events);

describe('PlayerManager — choix de l\'adaptateur', () => {
    it('charge un morceau avec l\'adaptateur de sa source', async () => {
        const manager = makeManager();

        await manager.load(1, spotifyTrack, { positionMs: 12_000, autoplay: true });

        expect(spotify.calls).toEqual(['load:sp1@12000:play']);
        expect(youtube.calls).toEqual([]);
        expect(manager.currentKey).toBe(1);
    });

    it('ne crée un adaptateur qu\'à la première utilisation, puis le réutilise', async () => {
        const manager = makeManager();

        expect(spotifyFactory).not.toHaveBeenCalled();

        await manager.load(1, youtubeTrack, playing);
        await manager.load(2, makeTrack({ provider: 'youtube', provider_track_id: 'yt2' }), playing);

        expect(youtubeFactory).toHaveBeenCalledTimes(1);
        expect(spotifyFactory).not.toHaveBeenCalled();
    });

    it('bascule d\'un adaptateur à l\'autre en faisant taire le précédent', async () => {
        const manager = makeManager();

        await manager.load(1, spotifyTrack, playing);
        await manager.load(2, youtubeTrack, playing);

        // Spotify est mis en pause AVANT que YouTube démarre : jamais deux sources en même temps.
        expect(spotify.calls).toEqual(['load:sp1@0:play', 'pause']);
        expect(youtube.calls).toEqual(['load:yt1@0:play']);

        await manager.load(3, spotifyTrack, playing);

        expect(youtube.calls).toEqual(['load:yt1@0:play', 'pause']);
        expect(spotify.calls).toEqual(['load:sp1@0:play', 'pause', 'load:sp1@0:play']);
    });

    it('ne met pas en pause quand deux morceaux de la même source s\'enchaînent', async () => {
        const manager = makeManager();

        await manager.load(1, youtubeTrack, playing);
        await manager.load(2, makeTrack({ provider: 'youtube', provider_track_id: 'yt2' }), playing);

        expect(youtube.calls).toEqual(['load:yt1@0:play', 'load:yt2@0:play']);
    });

    it('transmet play, pause et seek à l\'adaptateur actif uniquement', async () => {
        const manager = makeManager();
        await manager.load(1, spotifyTrack, playing);
        await manager.load(2, youtubeTrack, { positionMs: 0, autoplay: false });
        spotify.calls.length = 0;
        youtube.calls.length = 0;

        await manager.play();
        await manager.seek(30_000);
        await manager.pause();

        expect(youtube.calls).toEqual(['play', 'seek:30000', 'pause']);
        expect(spotify.calls).toEqual([]);
    });

    it('prépare tous les adaptateurs au déblocage de la session', async () => {
        const manager = makeManager();

        await manager.unlock();

        expect(spotify.calls).toEqual(['prepare']);
        expect(youtube.calls).toEqual(['prepare']);
    });
});

describe('PlayerManager — fin de morceau', () => {
    it('signale la fin du morceau courant avec sa clé', async () => {
        const manager = makeManager();
        await manager.load(42, youtubeTrack, playing);

        youtube.emitEnded();

        expect(events.onTrackEnded).toHaveBeenCalledExactlyOnceWith(42);
        expect(events.onTrackError).not.toHaveBeenCalled();
    });

    it('ne signale qu\'une fois un morceau qui émet deux fois "ended"', async () => {
        const manager = makeManager();
        await manager.load(42, youtubeTrack, playing);

        youtube.emitEnded();
        youtube.emitEnded();

        expect(events.onTrackEnded).toHaveBeenCalledTimes(1);
    });

    it('ignore la fin émise par un adaptateur qui n\'est plus actif', async () => {
        const manager = makeManager();
        await manager.load(1, spotifyTrack, playing);
        await manager.load(2, youtubeTrack, playing);

        // Le lecteur Spotify, mis en pause par la bascule, émet un "ended" tardif.
        spotify.emitEnded();

        expect(events.onTrackEnded).not.toHaveBeenCalled();
    });

    it('signale de nouveau la fin quand le même morceau repasse plus tard (nouvelle clé)', async () => {
        const manager = makeManager();

        await manager.load(1, youtubeTrack, playing);
        youtube.emitEnded();
        await manager.load(2, youtubeTrack, playing);
        youtube.emitEnded();

        expect(events.onTrackEnded.mock.calls).toEqual([[1], [2]]);
    });
});

describe('PlayerManager — saut automatique sur erreur', () => {
    it('demande de sauter le morceau quand l\'adaptateur signale une erreur (vidéo non intégrable)', async () => {
        const manager = makeManager();
        const error = new PlayerError('unplayable', "L'ayant droit interdit la lecture intégrée de cette vidéo");
        await manager.load(7, youtubeTrack, playing);

        youtube.emitError(error);

        expect(events.onTrackError).toHaveBeenCalledExactlyOnceWith(7, error);
        expect(events.onTrackEnded).not.toHaveBeenCalled();
    });

    it('demande de sauter le morceau quand le chargement échoue, sans propager l\'exception', async () => {
        const manager = makeManager();
        spotify.loadError = new PlayerError('unplayable', 'Spotify a refusé la lecture (HTTP 404)');

        await expect(manager.load(7, spotifyTrack, playing)).resolves.toBeUndefined();

        expect(events.onTrackError).toHaveBeenCalledExactlyOnceWith(7, spotify.loadError);
    });

    it('convertit une erreur inconnue en PlayerError', async () => {
        const manager = makeManager();
        youtube.loadError = new Error('boom');

        await manager.load(7, youtubeTrack, playing);

        const [key, error] = events.onTrackError.mock.calls[0] ?? [];

        expect(key).toBe(7);
        expect(error).toBeInstanceOf(PlayerError);
        expect(error?.code).toBe('playback');
        expect(error?.message).toBe('boom');
    });

    it('saute un morceau dont la source n\'a aucun adaptateur (Spotify sans Premium)', async () => {
        const manager = new PlayerManager({ youtube: youtubeFactory }, events);

        await manager.load(9, spotifyTrack, playing);

        expect(manager.supports('spotify')).toBe(false);
        expect(events.onTrackError).toHaveBeenCalledTimes(1);
        expect(events.onTrackError.mock.calls[0]?.[0]).toBe(9);
        expect(events.onTrackError.mock.calls[0]?.[1].code).toBe('provider_unavailable');
        expect(youtube.calls).toEqual([]);
    });

    it('ne signale qu\'une erreur par morceau, puis repart proprement sur le suivant', async () => {
        const manager = makeManager();
        await manager.load(1, youtubeTrack, playing);

        youtube.emitError(new PlayerError('unplayable', 'a'));
        youtube.emitError(new PlayerError('playback', 'b'));
        youtube.emitEnded(); // un "ended" parasite après l'erreur ne doit pas faire avancer une 2e fois

        expect(events.onTrackError).toHaveBeenCalledTimes(1);
        expect(events.onTrackEnded).not.toHaveBeenCalled();

        // Le salon passe au morceau suivant (autre source) : il se charge et se termine normalement.
        await manager.load(2, spotifyTrack, playing);
        spotify.emitEnded();

        expect(spotify.calls).toContain('load:sp1@0:play');
        expect(events.onTrackEnded).toHaveBeenCalledExactlyOnceWith(2);
    });

    it('ignore l\'échec d\'un chargement remplacé entre-temps par un autre', async () => {
        const manager = makeManager();
        let rejectLoad: (error: Error) => void = () => undefined;
        vi.spyOn(spotify, 'load').mockImplementationOnce(() => new Promise<void>((_, reject) => (rejectLoad = reject)));

        const slowLoad = manager.load(1, spotifyTrack, playing); // chargement lent…
        await Promise.resolve();
        await manager.load(2, youtubeTrack, playing); // …l'hôte a déjà passé au suivant
        rejectLoad(new PlayerError('unplayable', 'trop tard'));
        await slowLoad;

        expect(events.onTrackError).not.toHaveBeenCalled();
        expect(manager.currentKey).toBe(2);
    });

    it('saute le morceau si la reprise de lecture échoue, mais pas si la pause échoue', async () => {
        const manager = makeManager();
        await manager.load(5, youtubeTrack, { positionMs: 0, autoplay: false });
        vi.spyOn(youtube, 'pause').mockRejectedValueOnce(new Error('pause impossible'));

        await manager.pause();
        expect(events.onTrackError).not.toHaveBeenCalled();

        youtube.playError = new Error('lecture bloquée');
        await manager.play();
        expect(events.onTrackError).toHaveBeenCalledTimes(1);
    });
});

describe('PlayerManager — progression et cycle de vie', () => {
    it('relaie la progression de l\'adaptateur actif avec la clé du morceau', async () => {
        const manager = makeManager();
        await manager.load(1, spotifyTrack, playing);
        await manager.load(2, youtubeTrack, playing);

        spotify.emitProgress(1_000, 200_000); // inactif : ignoré
        youtube.emitProgress(5_000, 180_000);

        expect(events.onProgress).toHaveBeenCalledExactlyOnceWith(2, 5_000, 180_000);
    });

    it('met en pause et oublie le morceau courant sur stop', async () => {
        const manager = makeManager();
        await manager.load(1, youtubeTrack, playing);

        await manager.stop();
        youtube.emitEnded();

        expect(youtube.calls).toEqual(['load:yt1@0:play', 'pause']);
        expect(manager.currentKey).toBeNull();
        expect(events.onTrackEnded).not.toHaveBeenCalled();
    });

    it('détruit tous les adaptateurs créés', async () => {
        const manager = makeManager();
        await manager.load(1, spotifyTrack, playing);
        await manager.load(2, youtubeTrack, playing);

        manager.destroy();

        expect(spotify.calls).toContain('destroy');
        expect(youtube.calls).toContain('destroy');
    });
});
