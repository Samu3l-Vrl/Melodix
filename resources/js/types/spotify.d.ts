// resources/js/types/spotify.d.ts — déclarations minimales du Spotify Web Playback SDK.
// Le SDK est chargé par une balise <script> (https://sdk.scdn.co/spotify-player.js) et n'a pas
// de paquet npm : on ne décrit ici que la partie de l'API réellement utilisée par SpotifyAdapter.

declare namespace Spotify {
    interface PlayerInit {
        name: string;
        /** Appelé par le SDK chaque fois qu'il a besoin d'un jeton (au démarrage puis à l'expiration). */
        getOAuthToken: (callback: (token: string) => void) => void;
        volume?: number;
    }

    interface WebPlaybackTrack {
        id: string | null;
        uri: string;
        name: string;
        duration_ms: number;
    }

    interface WebPlaybackState {
        paused: boolean;
        position: number;
        duration: number;
        track_window: {
            current_track: WebPlaybackTrack;
            previous_tracks: WebPlaybackTrack[];
            next_tracks: WebPlaybackTrack[];
        };
    }

    interface WebPlaybackInstance {
        device_id: string;
    }

    interface WebPlaybackError {
        message: string;
    }

    type ErrorEvent = 'initialization_error' | 'authentication_error' | 'account_error' | 'playback_error';

    class Player {
        constructor(init: PlayerInit);
        connect(): Promise<boolean>;
        disconnect(): void;
        addListener(event: 'ready' | 'not_ready', callback: (instance: WebPlaybackInstance) => void): boolean;
        addListener(event: 'player_state_changed', callback: (state: WebPlaybackState | null) => void): boolean;
        addListener(event: ErrorEvent, callback: (error: WebPlaybackError) => void): boolean;
        removeListener(event: string): boolean;
        getCurrentState(): Promise<WebPlaybackState | null>;
        pause(): Promise<void>;
        resume(): Promise<void>;
        seek(positionMs: number): Promise<void>;
        /** Débloque l'audio sur les navigateurs qui exigent un geste utilisateur. */
        activateElement(): Promise<void>;
    }
}

interface Window {
    Spotify?: typeof Spotify;
    /** Callback global appelé par le SDK une fois chargé. */
    onSpotifyWebPlaybackSDKReady?: () => void;
}
