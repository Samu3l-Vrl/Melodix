// resources/js/__tests__/roomReducer.test.ts — contrat de versions appliqué aux events (fonctions pures).
import { applyPlaybackChanged, applyQueueUpdated, applySnapshot, classifyVersion } from '@/lib/roomReducer';
import { computePositionMs, formatDuration } from '@/lib/time';
import type { QueueUpdatedEvent } from '@/types';
import { describe, expect, it } from 'vitest';
import { makeItem, makePlayback, makeRoomState } from './factories';

const added = (version: number, item = makeItem()): QueueUpdatedEvent => ({
    version,
    server_time: 1_000,
    action: 'added',
    item,
    item_id: null,
    order: null,
});

describe('classifyVersion', () => {
    it('applique la version suivante, ignore les anciennes, signale les trous', () => {
        expect(classifyVersion(5, 6)).toBe('applied');
        expect(classifyVersion(5, 5)).toBe('ignored');
        expect(classifyVersion(5, 3)).toBe('ignored');
        expect(classifyVersion(5, 7)).toBe('gap');
    });
});

describe('applyQueueUpdated', () => {
    it('ajoute un élément en fin de file et avance la version', () => {
        const first = makeItem();
        const second = makeItem();
        const state = makeRoomState({ version: 1, queue: [first] });

        const result = applyQueueUpdated(state, added(2, second));

        expect(result.outcome).toBe('applied');
        expect(result.state.queue.map((item) => item.id)).toEqual([first.id, second.id]);
        expect(result.state.version).toBe(2);
        expect(result.state.server_time).toBe(1_000);
        // Immutabilité : l'état d'origine n'est pas modifié (React compare par référence).
        expect(state.queue).toHaveLength(1);
    });

    it('ignore un event déjà connu sans toucher à l\'état', () => {
        const state = makeRoomState({ version: 4 });

        const result = applyQueueUpdated(state, added(4));

        expect(result.outcome).toBe('ignored');
        expect(result.state).toBe(state);
    });

    it('signale un trou de version sans appliquer le delta', () => {
        const state = makeRoomState({ version: 4 });

        const result = applyQueueUpdated(state, added(6));

        expect(result.outcome).toBe('gap');
        expect(result.state).toBe(state);
    });

    it('retire un élément', () => {
        const [a, b] = [makeItem(), makeItem()];
        const state = makeRoomState({ version: 2, queue: [a, b] });

        const result = applyQueueUpdated(state, { version: 3, server_time: 1, action: 'removed', item: null, item_id: a.id, order: null });

        expect(result.state.queue).toEqual([b]);
    });

    it('réordonne la file selon la liste d\'ids reçue', () => {
        const [a, b, c] = [makeItem(), makeItem(), makeItem()];
        const state = makeRoomState({ version: 3, queue: [a, b, c] });

        const result = applyQueueUpdated(state, {
            version: 4,
            server_time: 1,
            action: 'reordered',
            item: null,
            item_id: null,
            order: [c.id, a.id, b.id],
        });

        expect(result.state.queue).toEqual([c, a, b]);
    });

    it('demande un resync si l\'ordre reçu ne correspond pas à la file locale', () => {
        const [a, b] = [makeItem(), makeItem()];
        const state = makeRoomState({ version: 3, queue: [a, b] });

        const result = applyQueueUpdated(state, { version: 4, server_time: 1, action: 'reordered', item: null, item_id: null, order: [b.id, 999] });

        expect(result.outcome).toBe('gap');
    });
});

describe('applyPlaybackChanged', () => {
    it('remplace l\'état de lecture et retire de la file le morceau qui démarre', () => {
        const [a, b] = [makeItem(), makeItem()];
        const state = makeRoomState({ version: 2, queue: [a, b] });
        const playback = makePlayback({ status: 'playing', current: { ...a, status: 'playing' }, position_updated_at: 5_000 });

        const result = applyPlaybackChanged(state, { version: 3, server_time: 5_000, playback });

        expect(result.outcome).toBe('applied');
        expect(result.state.playback).toBe(playback);
        expect(result.state.queue).toEqual([b]);
        expect(result.state.version).toBe(3);
    });

    it('ignore les events anciens et signale les trous', () => {
        const state = makeRoomState({ version: 5 });
        const playback = makePlayback({ status: 'paused' });

        expect(applyPlaybackChanged(state, { version: 5, server_time: 1, playback }).outcome).toBe('ignored');
        expect(applyPlaybackChanged(state, { version: 8, server_time: 1, playback }).outcome).toBe('gap');
    });
});

describe('applySnapshot', () => {
    it('accepte un état plus récent ou égal, refuse un état plus ancien', () => {
        const state = makeRoomState({ version: 5 });
        const newer = makeRoomState({ version: 9 });
        const older = makeRoomState({ version: 2 });

        expect(applySnapshot(state, newer)).toBe(newer);
        expect(applySnapshot(state, older)).toBe(state);
    });
});

describe('computePositionMs', () => {
    const current = makeItem();

    it('extrapole la position en lecture à partir de l\'horloge serveur', () => {
        const playback = makePlayback({ status: 'playing', current, position_ms: 10_000, position_updated_at: 100_000 });

        // Horloge locale = 104 000, décalage serveur = +500 ms -> 4 500 ms écoulées.
        expect(computePositionMs(playback, 500, 104_000)).toBe(14_500);
    });

    it('fige la position en pause', () => {
        const playback = makePlayback({ status: 'paused', current, position_ms: 42_000, position_updated_at: 100_000 });

        expect(computePositionMs(playback, 0, 999_999)).toBe(42_000);
    });

    it('ne dépasse jamais la durée du morceau et ne recule jamais avant le point de référence', () => {
        const playback = makePlayback({ status: 'playing', current, position_ms: 190_000, position_updated_at: 100_000 });

        expect(computePositionMs(playback, 0, 500_000)).toBe(current.track.duration_ms);
        // Horloge locale en avance sur le serveur : "temps écoulé" négatif ramené à 0.
        expect(computePositionMs(playback, -5_000, 101_000)).toBe(190_000);
    });
});

describe('formatDuration', () => {
    it('formate minutes:secondes et heures', () => {
        expect(formatDuration(253_000)).toBe('4:13');
        expect(formatDuration(5_000)).toBe('0:05');
        expect(formatDuration(3_723_000)).toBe('1:02:03');
        expect(formatDuration(-10)).toBe('0:00');
    });
});
