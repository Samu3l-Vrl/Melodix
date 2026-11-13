// resources/js/lib/roomReducer.ts — application des events temps réel à l'état local, par version.
// Fonctions PURES (aucun effet de bord) : toute la logique du contrat de versions est testable sans React.
import type { PlaybackStateChangedEvent, QueueItem, QueueUpdatedEvent, RoomState } from '@/types';

/**
 * Résultat de l'application d'un event :
 *  - applied : l'event était le suivant attendu (version locale + 1), l'état a changé ;
 *  - ignored : event déjà connu (version <= locale) — doublon ou arrivée dans le désordre ;
 *  - gap     : au moins un event a été manqué (version > locale + 1). Les events étant des DELTAS,
 *              on ne peut pas l'appliquer : il faut recharger l'état complet.
 */
export type ApplyOutcome = 'applied' | 'ignored' | 'gap';

export interface ApplyResult {
    state: RoomState;
    outcome: ApplyOutcome;
}

/** Classe un numéro de version entrant par rapport à la version locale. */
export function classifyVersion(localVersion: number, incomingVersion: number): ApplyOutcome {
    if (incomingVersion <= localVersion) {
        return 'ignored';
    }

    return incomingVersion === localVersion + 1 ? 'applied' : 'gap';
}

/** Applique un event QueueUpdated (ajout, retrait ou réordonnancement). */
export function applyQueueUpdated(state: RoomState, event: QueueUpdatedEvent): ApplyResult {
    const outcome = classifyVersion(state.version, event.version);

    if (outcome !== 'applied') {
        return { state, outcome };
    }

    let queue: QueueItem[];

    switch (event.action) {
        case 'added':
            // Garde-fou : ne jamais dupliquer un élément déjà présent.
            queue = state.queue.some((item) => item.id === event.item.id) ? state.queue : [...state.queue, event.item];
            break;

        case 'removed':
            queue = state.queue.filter((item) => item.id !== event.item_id);
            break;

        case 'reordered': {
            const byId = new Map(state.queue.map((item) => [item.id, item]));
            const reordered = event.order.flatMap((id) => {
                const item = byId.get(id);

                return item ? [item] : [];
            });

            // L'ordre reçu doit couvrir exactement notre file ; sinon notre état est incohérent -> resync.
            if (reordered.length !== state.queue.length) {
                return { state, outcome: 'gap' };
            }

            queue = reordered;
            break;
        }
    }

    return {
        state: { ...state, queue, version: event.version, server_time: event.server_time },
        outcome: 'applied',
    };
}

/** Applique un event PlaybackStateChanged (play, pause, seek, changement de morceau, arrêt). */
export function applyPlaybackChanged(state: RoomState, event: PlaybackStateChangedEvent): ApplyResult {
    const outcome = classifyVersion(state.version, event.version);

    if (outcome !== 'applied') {
        return { state, outcome };
    }

    // Le morceau qui démarre quitte la file d'attente : le serveur n'envoie pas d'event de file pour ça.
    const currentId = event.playback.current?.id;
    const queue = currentId === undefined ? state.queue : state.queue.filter((item) => item.id !== currentId);

    return {
        state: { ...state, queue, playback: event.playback, version: event.version, server_time: event.server_time },
        outcome: 'applied',
    };
}

/**
 * Remplace l'état local par un état complet (réponse d'une action ou d'un resync),
 * sauf s'il est plus ancien que ce qu'on connaît déjà (réponse HTTP doublée par un event).
 */
export function applySnapshot(state: RoomState, snapshot: RoomState): RoomState {
    return snapshot.version >= state.version ? snapshot : state;
}
