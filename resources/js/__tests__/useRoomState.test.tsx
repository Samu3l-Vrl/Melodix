// resources/js/__tests__/useRoomState.test.tsx — hook temps réel : ordre des versions, resync, état de connexion.
import { useRoomState } from '@/hooks/useRoomState';
import { api } from '@/lib/api';
import type { RoomHandlers } from '@/lib/echo';
import type { ConnectionState, PlaybackStateChangedEvent, QueueUpdatedEvent, RoomState } from '@/types';
import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { makeItem, makePlayback, makeRoomState } from './factories';

/**
 * Faux module temps réel : capture les handlers enregistrés par le hook pour que les tests
 * puissent "émettre" des events et des changements de connexion à la main.
 */
const realtime = vi.hoisted(() => ({
    handlers: null as RoomHandlers | null,
    connectionListener: null as ((state: ConnectionState) => void) | null,
    unsubscribeRoom: vi.fn(),
    unsubscribeConnection: vi.fn(),
    subscribedCode: null as string | null,
}));

vi.mock('@/lib/echo', () => ({
    subscribeToRoom: (code: string, handlers: RoomHandlers) => {
        realtime.subscribedCode = code;
        realtime.handlers = handlers;

        return realtime.unsubscribeRoom;
    },
    onConnectionChange: (listener: (state: ConnectionState) => void) => {
        realtime.connectionListener = listener;
        // Comme le vrai module : l'état courant est communiqué immédiatement.
        listener('reconnecting');

        return realtime.unsubscribeConnection;
    },
}));

vi.mock('@/lib/api', async (importOriginal) => {
    const original = await importOriginal<typeof import('@/lib/api')>();

    return { ...original, api: { ...original.api, fetchRoomState: vi.fn() } };
});

const fetchRoomState = vi.mocked(api.fetchRoomState);

const emitQueue = (event: QueueUpdatedEvent): void => act(() => realtime.handlers?.onQueueUpdated(event));
const emitPlayback = (event: PlaybackStateChangedEvent): void => act(() => realtime.handlers?.onPlaybackChanged(event));
const setConnection = (state: ConnectionState): void => act(() => realtime.connectionListener?.(state));

const addedEvent = (version: number, item = makeItem()): QueueUpdatedEvent => ({
    version,
    server_time: Date.now(),
    action: 'added',
    item,
    item_id: null,
    order: null,
});

/** Laisse les promesses en attente (fetch simulé) se résoudre. */
const flush = (): Promise<void> => act(async () => {
    await Promise.resolve();
});

beforeEach(() => {
    realtime.handlers = null;
    realtime.connectionListener = null;
    realtime.unsubscribeRoom.mockReset();
    realtime.unsubscribeConnection.mockReset();
    fetchRoomState.mockReset();
    // Par défaut le resync ne renvoie rien de neuf ; chaque test précise sa réponse au besoin.
    fetchRoomState.mockImplementation(() => new Promise<RoomState>(() => undefined));
});

describe('useRoomState', () => {
    it('part de l\'état fourni par les props Inertia et s\'abonne au canal du salon', () => {
        const initial = makeRoomState({ code: 'KZPW', version: 3, queue: [makeItem()] });

        const { result } = renderHook(() => useRoomState('KZPW', initial));

        expect(result.current.state).toBe(initial);
        expect(result.current.connection).toBe('reconnecting');
        expect(realtime.subscribedCode).toBe('KZPW');
        expect(fetchRoomState).not.toHaveBeenCalled();
    });

    it('applique les events dans l\'ordre des versions', () => {
        const [first, second] = [makeItem(), makeItem()];
        const { result } = renderHook(() => useRoomState('ABCD', makeRoomState({ version: 0 })));

        emitQueue(addedEvent(1, first));
        emitQueue(addedEvent(2, second));

        expect(result.current.state.version).toBe(2);
        expect(result.current.state.queue.map((item) => item.id)).toEqual([first.id, second.id]);
        expect(fetchRoomState).not.toHaveBeenCalled();
    });

    it('ignore un event dont la version est inférieure ou égale à la version locale', () => {
        const existing = makeItem();
        const { result } = renderHook(() => useRoomState('ABCD', makeRoomState({ version: 5, queue: [existing] })));
        const before = result.current.state;

        emitQueue(addedEvent(5)); // doublon de la version courante
        emitQueue(addedEvent(2)); // vieil event arrivé en retard

        expect(result.current.state).toBe(before);
        expect(fetchRoomState).not.toHaveBeenCalled();
    });

    it('recharge l\'état complet quand un trou de version est détecté', async () => {
        const snapshot = makeRoomState({ version: 4, queue: [makeItem(), makeItem(), makeItem()] });
        fetchRoomState.mockResolvedValue(snapshot);
        const { result } = renderHook(() => useRoomState('ABCD', makeRoomState({ version: 1 })));

        emitQueue(addedEvent(4)); // les versions 2 et 3 ont été perdues

        // Le delta n'est PAS appliqué tel quel…
        expect(result.current.state.version).toBe(1);
        expect(fetchRoomState).toHaveBeenCalledExactlyOnceWith('ABCD');

        // …c'est l'état complet du serveur qui fait foi.
        await waitFor(() => expect(result.current.state).toBe(snapshot));
    });

    it('ne lance qu\'un seul resync pour une rafale d\'events en avance', async () => {
        let resolve: (state: RoomState) => void = () => undefined;
        fetchRoomState.mockImplementation(() => new Promise<RoomState>((r) => (resolve = r)));
        const { result } = renderHook(() => useRoomState('ABCD', makeRoomState({ version: 1 })));

        emitQueue(addedEvent(5));
        emitQueue(addedEvent(6));
        emitQueue(addedEvent(7));

        expect(fetchRoomState).toHaveBeenCalledTimes(1);

        await act(async () => resolve(makeRoomState({ version: 7 })));
        expect(result.current.state.version).toBe(7);

        // Le fil normal reprend ensuite : la version 8 s'applique directement.
        emitQueue(addedEvent(8));
        expect(result.current.state.version).toBe(8);
        expect(fetchRoomState).toHaveBeenCalledTimes(1);
    });

    it('n\'écrase pas un état plus récent avec une réponse de resync en retard', async () => {
        let resolve: (state: RoomState) => void = () => undefined;
        fetchRoomState.mockImplementation(() => new Promise<RoomState>((r) => (resolve = r)));
        const { result } = renderHook(() => useRoomState('ABCD', makeRoomState({ version: 1 })));

        emitQueue(addedEvent(3)); // trou -> resync en cours
        act(() => result.current.applyServerState(makeRoomState({ version: 6 }))); // réponse d'une action, plus récente

        await act(async () => resolve(makeRoomState({ version: 4 }))); // le resync répond enfin, avec un état dépassé

        expect(result.current.state.version).toBe(6);
    });

    it('se resynchronise à la connexion puis à chaque reconnexion', async () => {
        fetchRoomState.mockResolvedValue(makeRoomState({ version: 2 }));
        const { result } = renderHook(() => useRoomState('ABCD', makeRoomState({ version: 0 })));

        setConnection('connected');
        await flush();

        expect(result.current.connection).toBe('connected');
        expect(fetchRoomState).toHaveBeenCalledTimes(1);
        expect(result.current.state.version).toBe(2);

        // Coupure réseau : des events sont perdus pendant ce temps.
        setConnection('offline');
        expect(result.current.connection).toBe('offline');

        fetchRoomState.mockResolvedValue(makeRoomState({ version: 9 }));
        setConnection('reconnecting');
        setConnection('connected');
        await flush();

        expect(fetchRoomState).toHaveBeenCalledTimes(2);
        expect(result.current.state.version).toBe(9);
    });

    it('réessaie le resync après un échec réseau', async () => {
        vi.useFakeTimers();

        try {
            fetchRoomState.mockRejectedValueOnce(new Error('réseau coupé')).mockResolvedValueOnce(makeRoomState({ version: 5 }));
            const { result } = renderHook(() => useRoomState('ABCD', makeRoomState({ version: 1 })));

            emitQueue(addedEvent(5));
            await flush();
            expect(result.current.state.version).toBe(1);

            await act(async () => {
                await vi.advanceTimersByTimeAsync(3000);
            });

            expect(fetchRoomState).toHaveBeenCalledTimes(2);
            expect(result.current.state.version).toBe(5);
        } finally {
            vi.useRealTimers();
        }
    });

    it('applique les changements de lecture et retire de la file le morceau qui démarre', () => {
        const [a, b] = [makeItem(), makeItem()];
        const { result } = renderHook(() => useRoomState('ABCD', makeRoomState({ version: 2, queue: [a, b] })));

        emitPlayback({
            version: 3,
            server_time: Date.now(),
            playback: makePlayback({ status: 'playing', current: { ...a, status: 'playing' }, position_updated_at: Date.now() }),
        });

        expect(result.current.state.playback.status).toBe('playing');
        expect(result.current.state.playback.current?.id).toBe(a.id);
        expect(result.current.state.queue).toEqual([b]);
    });

    it('applique immédiatement l\'état renvoyé par une action, puis ignore l\'event correspondant', () => {
        const item = makeItem();
        const { result } = renderHook(() => useRoomState('ABCD', makeRoomState({ version: 0 })));
        const response = makeRoomState({ version: 1, queue: [item] });

        act(() => result.current.applyServerState(response));
        expect(result.current.state).toBe(response);

        // L'event de la même mutation arrive juste après : même version, donc ignoré (pas de doublon dans la file).
        emitQueue(addedEvent(1, item));
        expect(result.current.state.queue).toHaveLength(1);
    });

    it('estime le décalage d\'horloge à partir de server_time', () => {
        const now = Date.now();
        const { result } = renderHook(() => useRoomState('ABCD', makeRoomState({ version: 0, server_time: now + 60_000 })));

        // Le serveur a ~60 s d'avance sur cet appareil.
        expect(result.current.clockOffsetMs).toBeGreaterThan(59_000);
        expect(result.current.clockOffsetMs).toBeLessThanOrEqual(60_000);
    });

    it('se désabonne au démontage', () => {
        const { unmount } = renderHook(() => useRoomState('ABCD', makeRoomState()));

        unmount();

        expect(realtime.unsubscribeRoom).toHaveBeenCalledTimes(1);
        expect(realtime.unsubscribeConnection).toHaveBeenCalledTimes(1);
    });
});
