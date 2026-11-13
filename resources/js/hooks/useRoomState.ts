// resources/js/hooks/useRoomState.ts — état temps réel d'un salon, commun à l'écran hôte et à la télécommande.
// Part des props Inertia, s'abonne au canal public, applique les events dans l'ordre des versions
// et se resynchronise tout seul (trou de version, reconnexion).
import { api } from '@/lib/api';
import { onConnectionChange, subscribeToRoom } from '@/lib/echo';
import { applyPlaybackChanged, applyQueueUpdated, applySnapshot, type ApplyResult } from '@/lib/roomReducer';
import type { ConnectionState, RoomState } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface UseRoomState {
    /** Dernier état connu du salon. */
    state: RoomState;
    /** État de la connexion WebSocket. */
    connection: ConnectionState;
    /** Décalage estimé (ms) entre l'horloge du serveur et celle de cet appareil. */
    clockOffsetMs: number;
    /** Applique un état complet reçu en réponse d'une action (sans attendre l'event correspondant). */
    applyServerState: (snapshot: RoomState) => void;
    /** Recharge l'état complet depuis le serveur. */
    resync: () => Promise<void>;
}

/** Délai avant de retenter un resync qui a échoué (réseau coupé). */
const RESYNC_RETRY_MS = 3000;

export function useRoomState(code: string, initial: RoomState): UseRoomState {
    const [state, setState] = useState<RoomState>(initial);
    const [connection, setConnection] = useState<ConnectionState>('reconnecting');
    const [clockOffsetMs, setClockOffsetMs] = useState<number>(() => initial.server_time - Date.now());

    // Référence toujours à jour : les callbacks d'events sont créés une seule fois et ne doivent
    // pas travailler sur un état périmé (piège classique des fermetures avec useState).
    const stateRef = useRef<RoomState>(initial);
    const resyncingRef = useRef(false);
    const retryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const mountedRef = useRef(true);

    /**
     * Enregistre un nouvel état et affine l'estimation du décalage d'horloge.
     * La latence réseau ne peut que faire paraître l'horloge serveur "en retard" (server_time est
     * déjà vieux quand il arrive) : le meilleur échantillon est donc le plus GRAND décalage observé.
     */
    const commit = useCallback((next: RoomState) => {
        if (next === stateRef.current) {
            return;
        }

        stateRef.current = next;
        setState(next);
        setClockOffsetMs((previous) => Math.max(previous, next.server_time - Date.now()));
    }, []);

    const resync = useCallback(async (): Promise<void> => {
        // Un seul resync à la fois : une rafale d'events "en avance" ne déclenche qu'un rechargement.
        if (resyncingRef.current) {
            return;
        }

        resyncingRef.current = true;

        try {
            const snapshot = await api.fetchRoomState(code);

            if (mountedRef.current) {
                // applySnapshot écarte une réponse plus ancienne que l'état local (event arrivé entre-temps).
                commit(applySnapshot(stateRef.current, snapshot));
            }
        } catch {
            // Réseau indisponible : on réessaie plus tard, sans empiler les minuteries.
            if (mountedRef.current && retryTimerRef.current === null) {
                retryTimerRef.current = setTimeout(() => {
                    retryTimerRef.current = null;
                    void resync();
                }, RESYNC_RETRY_MS);
            }
        } finally {
            resyncingRef.current = false;
        }
    }, [code, commit]);

    const applyServerState = useCallback(
        (snapshot: RoomState) => {
            commit(applySnapshot(stateRef.current, snapshot));
        },
        [commit],
    );

    useEffect(() => {
        mountedRef.current = true;

        /** Contrat de versions : appliquer, ignorer, ou recharger l'état complet. */
        const handle = (result: ApplyResult): void => {
            if (result.outcome === 'applied') {
                commit(result.state);
            } else if (result.outcome === 'gap') {
                void resync();
            }
            // 'ignored' : event déjà connu, rien à faire.
        };

        const unsubscribeRoom = subscribeToRoom(code, {
            onQueueUpdated: (event) => handle(applyQueueUpdated(stateRef.current, event)),
            onPlaybackChanged: (event) => handle(applyPlaybackChanged(stateRef.current, event)),
        });

        let wasConnected = false;
        let hasConnectedOnce = false;

        const unsubscribeConnection = onConnectionChange((next) => {
            setConnection(next);

            if (next === 'connected' && !wasConnected) {
                // Première connexion : les props Inertia peuvent dater de quelques centaines de ms.
                // Reconnexion : des events ont pu être perdus pendant la coupure.
                // Dans les deux cas, un rechargement de l'état complet garantit qu'on repart juste.
                void resync();
                hasConnectedOnce = true;
            }

            wasConnected = next === 'connected';
        });

        // Retour de l'onglet au premier plan (téléphone sorti de veille) : la socket peut encore
        // se croire connectée alors que des events ont été perdus.
        const onVisible = (): void => {
            if (document.visibilityState === 'visible' && hasConnectedOnce) {
                void resync();
            }
        };

        document.addEventListener('visibilitychange', onVisible);

        return () => {
            mountedRef.current = false;
            unsubscribeRoom();
            unsubscribeConnection();
            document.removeEventListener('visibilitychange', onVisible);

            if (retryTimerRef.current !== null) {
                clearTimeout(retryTimerRef.current);
                retryTimerRef.current = null;
            }
        };
    }, [code, commit, resync]);

    return { state, connection, clockOffsetMs, applyServerState, resync };
}
