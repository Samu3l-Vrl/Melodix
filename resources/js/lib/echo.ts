// resources/js/lib/echo.ts — connexion temps réel (Laravel Echo + Reverb) et abonnements aux canaux d'un salon.
// Ce module est le SEUL à connaître Echo/Pusher : le reste de l'application ne voit que des fonctions typées,
// ce qui rend les hooks faciles à tester (il suffit de simuler ce module).
import type { ConnectionState, GuestJoinedEvent, PlaybackStateChangedEvent, QueueUpdatedEvent } from '@/types';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let instance: Echo<'reverb'> | null = null;

/** Crée la connexion au premier appel, puis renvoie toujours la même (une seule socket par onglet). */
function getEcho(): Echo<'reverb'> {
    if (instance) {
        return instance;
    }

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);

    instance = new Echo<'reverb'>({
        broadcaster: 'reverb',
        // Reverb parle le protocole Pusher : on fournit le client explicitement plutôt que via window.Pusher.
        Pusher,
        key: import.meta.env.VITE_REVERB_APP_KEY,
        // Par défaut : le même nom d'hôte que la page. L'hôte (127.0.0.1) et les téléphones
        // (IP du réseau local) atteignent ainsi tous Reverb sans configuration par appareil.
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return instance;
}

export interface RoomHandlers {
    onQueueUpdated: (event: QueueUpdatedEvent) => void;
    onPlaybackChanged: (event: PlaybackStateChangedEvent) => void;
}

/**
 * S'abonne au canal PUBLIC `room.{code}` (état de la file et de la lecture).
 * Le point devant le nom d'event indique à Echo de ne pas préfixer par le namespace PHP :
 * les events utilisent des noms courts via broadcastAs().
 *
 * @returns Fonction de désabonnement.
 */
export function subscribeToRoom(code: string, handlers: RoomHandlers): () => void {
    const channelName = `room.${code}`;

    getEcho()
        .channel(channelName)
        .listen('.queue.updated', handlers.onQueueUpdated)
        .listen('.playback.changed', handlers.onPlaybackChanged);

    return () => getEcho().leaveChannel(channelName);
}

export interface HostHandlers {
    onGuestJoined: (event: GuestJoinedEvent) => void;
}

/**
 * S'abonne au canal PRIVÉ de l'hôte (`private-room.{code}.host`, le préfixe est ajouté par Echo).
 * Echo appelle POST /broadcasting/auth avec le cookie de session : seul le propriétaire est accepté.
 */
export function subscribeToHost(code: string, handlers: HostHandlers): () => void {
    const channelName = `room.${code}.host`;

    getEcho().private(channelName).listen('.guest.joined', handlers.onGuestJoined);

    return () => getEcho().leaveChannel(`private-${channelName}`);
}

/** Traduit les états internes de pusher-js en trois états compréhensibles par l'utilisateur. */
export function toConnectionState(pusherState: string): ConnectionState {
    switch (pusherState) {
        case 'connected':
            return 'connected';
        case 'initialized':
        case 'connecting':
            return 'reconnecting';
        default:
            // unavailable (pas de réseau, nouvelles tentatives espacées), failed, disconnected.
            return 'offline';
    }
}

/**
 * Observe l'état de la connexion. Le callback est appelé immédiatement avec l'état courant,
 * puis à chaque changement.
 *
 * @returns Fonction pour arrêter l'observation.
 */
export function onConnectionChange(callback: (state: ConnectionState) => void): () => void {
    const connection = getEcho().connector.pusher.connection;
    const handler = (states: { previous: string; current: string }): void => callback(toConnectionState(states.current));

    callback(toConnectionState(connection.state));
    connection.bind('state_change', handler);

    return () => {
        connection.unbind('state_change', handler);
    };
}
