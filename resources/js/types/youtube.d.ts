// resources/js/types/youtube.d.ts — déclarations minimales de la YouTube IFrame Player API.
// L'API est chargée par une balise <script> (https://www.youtube.com/iframe_api) : on ne décrit
// que ce que YouTubeAdapter utilise.

declare namespace YT {
    /** Valeurs de YT.PlayerState. */
    const enum PlayerState {
        UNSTARTED = -1,
        ENDED = 0,
        PLAYING = 1,
        PAUSED = 2,
        BUFFERING = 3,
        CUED = 5,
    }

    interface PlayerVars {
        autoplay?: 0 | 1;
        controls?: 0 | 1;
        rel?: 0 | 1;
        playsinline?: 0 | 1;
        origin?: string;
    }

    interface OnStateChangeEvent {
        data: number;
        target: Player;
    }

    interface OnErrorEvent {
        /** 2 = id invalide, 5 = erreur HTML5, 100 = vidéo introuvable, 101/150 = intégration interdite. */
        data: number;
        target: Player;
    }

    interface PlayerEvent {
        target: Player;
    }

    interface PlayerOptions {
        width?: string | number;
        height?: string | number;
        videoId?: string;
        playerVars?: PlayerVars;
        events?: {
            onReady?: (event: PlayerEvent) => void;
            onStateChange?: (event: OnStateChangeEvent) => void;
            onError?: (event: OnErrorEvent) => void;
        };
    }

    interface VideoByIdSettings {
        videoId: string;
        startSeconds?: number;
    }

    class Player {
        constructor(element: HTMLElement | string, options: PlayerOptions);
        loadVideoById(settings: VideoByIdSettings): void;
        cueVideoById(settings: VideoByIdSettings): void;
        playVideo(): void;
        pauseVideo(): void;
        stopVideo(): void;
        seekTo(seconds: number, allowSeekAhead: boolean): void;
        getCurrentTime(): number;
        getDuration(): number;
        getPlayerState(): number;
        destroy(): void;
    }
}

interface Window {
    YT?: typeof YT;
    /** Callback global appelé par l'API une fois chargée. */
    onYouTubeIframeAPIReady?: () => void;
}
