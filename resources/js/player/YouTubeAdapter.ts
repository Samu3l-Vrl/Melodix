// resources/js/player/YouTubeAdapter.ts — lecture YouTube via l'IFrame Player API officielle.
// Respect des conditions YouTube : lecteur VISIBLE (au moins 200x200), commandes natives conservées,
// aucune extraction audio, aucun contournement des publicités (elles s'affichent dans le lecteur).
import {
    Emitter,
    PlayerError,
    type EndedListener,
    type ErrorListener,
    type LoadOptions,
    type PlayerAdapter,
    type ProgressListener,
    type Unsubscribe,
} from '@/player/PlayerAdapter';
import { loadScript } from '@/player/loadScript';
import type { Track } from '@/types';

const IFRAME_API_URL = 'https://www.youtube.com/iframe_api';
const PROGRESS_INTERVAL_MS = 500;

// Valeurs de YT.PlayerState (dupliquées ici : un `const enum` ambiant n'existe pas à l'exécution).
const STATE_ENDED = 0;
const STATE_PLAYING = 1;

let apiReady: Promise<void> | null = null;

/** Charge l'API une seule fois et attend son callback global `onYouTubeIframeAPIReady`. */
function loadIframeApi(): Promise<void> {
    if (window.YT?.Player) {
        return Promise.resolve();
    }

    apiReady ??= new Promise<void>((resolve, reject) => {
        const previous = window.onYouTubeIframeAPIReady;

        window.onYouTubeIframeAPIReady = () => {
            previous?.();
            resolve();
        };

        loadScript(IFRAME_API_URL).catch((error: unknown) => {
            apiReady = null;
            reject(error instanceof Error ? error : new PlayerError('init', 'API YouTube indisponible'));
        });
    });

    return apiReady;
}

export class YouTubeAdapter implements PlayerAdapter {
    readonly provider = 'youtube' as const;

    private player: YT.Player | null = null;
    private playerReady: Promise<YT.Player> | null = null;
    private progressTimer: ReturnType<typeof setInterval> | null = null;
    private destroyed = false;

    private readonly progress = new Emitter<[number, number]>();
    private readonly ended = new Emitter<[]>();
    private readonly errors = new Emitter<[PlayerError]>();

    /**
     * @param container Élément dans lequel le lecteur est inséré. Il doit rester visible et mesurer
     *                  au moins 200x200 px pendant la lecture (exigence de YouTube) : c'est la page
     *                  hôte qui en garantit la taille.
     */
    constructor(private readonly container: HTMLElement) {}

    async prepare(): Promise<void> {
        await this.ensurePlayer();
    }

    async load(track: Track, options: LoadOptions): Promise<void> {
        const player = await this.ensurePlayer();
        const settings = { videoId: track.provider_track_id, startSeconds: options.positionMs / 1000 };

        if (options.autoplay) {
            player.loadVideoById(settings);
        } else {
            // cue = prépare la vidéo (miniature + bouton) sans la lancer.
            player.cueVideoById(settings);
        }
        // Les erreurs de chargement (vidéo supprimée, intégration interdite) n'arrivent pas ici :
        // YouTube les signale de façon asynchrone via onError -> this.errors.
    }

    async play(): Promise<void> {
        (await this.ensurePlayer()).playVideo();
    }

    async pause(): Promise<void> {
        // Pas de création de lecteur juste pour le mettre en pause.
        this.player?.pauseVideo();
    }

    async seek(positionMs: number): Promise<void> {
        // allowSeekAhead = true : autorise une nouvelle requête réseau si la position n'est pas en mémoire tampon.
        this.player?.seekTo(positionMs / 1000, true);
    }

    async setVolume(volume: number): Promise<void> {
        // L'API YouTube attend un entier de 0 à 100. Le volume est conservé d'une vidéo à l'autre.
        this.player?.setVolume(Math.round(Math.min(1, Math.max(0, volume)) * 100));
    }

    destroy(): void {
        this.destroyed = true;
        this.stopProgress();
        this.player?.destroy();
        this.player = null;
        this.playerReady = null;
        this.progress.clear();
        this.ended.clear();
        this.errors.clear();
    }

    onProgress(listener: ProgressListener): Unsubscribe {
        return this.progress.subscribe(listener);
    }

    onEnded(listener: EndedListener): Unsubscribe {
        return this.ended.subscribe(listener);
    }

    onError(listener: ErrorListener): Unsubscribe {
        return this.errors.subscribe(listener);
    }

    /** Crée le lecteur à la première utilisation ; les appels suivants attendent la même promesse. */
    private ensurePlayer(): Promise<YT.Player> {
        this.playerReady ??= loadIframeApi().then(
            () =>
                new Promise<YT.Player>((resolve, reject) => {
                    if (this.destroyed || !window.YT) {
                        reject(new PlayerError('init', 'Lecteur YouTube indisponible'));

                        return;
                    }

                    // L'API REMPLACE l'élément fourni par une <iframe> : on lui donne un enfant jetable,
                    // pour que le conteneur géré par React reste intact.
                    const mount = document.createElement('div');
                    this.container.replaceChildren(mount);

                    const player = new window.YT.Player(mount, {
                        width: '100%',
                        height: '100%',
                        playerVars: {
                            autoplay: 0,
                            // Commandes natives conservées : on n'altère pas le lecteur.
                            controls: 1,
                            rel: 0,
                            playsinline: 1,
                            origin: window.location.origin,
                        },
                        events: {
                            onReady: () => {
                                this.player = player;
                                resolve(player);
                            },
                            onStateChange: (event) => this.handleStateChange(event.data),
                            onError: (event) => this.errors.emit(toPlayerError(event.data)),
                        },
                    });
                }),
        );

        return this.playerReady;
    }

    private handleStateChange(state: number): void {
        if (state === STATE_PLAYING) {
            this.startProgress();

            return;
        }

        this.stopProgress();

        if (state === STATE_ENDED) {
            this.ended.emit();
        }
    }

    /** L'API n'émet pas d'event de progression : on interroge le lecteur à intervalle régulier. */
    private startProgress(): void {
        if (this.progressTimer !== null) {
            return;
        }

        this.progressTimer = setInterval(() => {
            if (this.player) {
                this.progress.emit(this.player.getCurrentTime() * 1000, this.player.getDuration() * 1000);
            }
        }, PROGRESS_INTERVAL_MS);
    }

    private stopProgress(): void {
        if (this.progressTimer !== null) {
            clearInterval(this.progressTimer);
            this.progressTimer = null;
        }
    }
}

/** Traduit les codes d'erreur de l'IFrame API en erreurs communes. */
function toPlayerError(code: number): PlayerError {
    switch (code) {
        case 100:
            return new PlayerError('unplayable', 'Vidéo introuvable ou privée');
        case 101:
        case 150:
            return new PlayerError('unplayable', "L'ayant droit interdit la lecture intégrée de cette vidéo");
        case 2:
            return new PlayerError('unplayable', 'Identifiant de vidéo invalide');
        case 5:
            return new PlayerError('playback', 'Le navigateur ne parvient pas à lire cette vidéo (HTML5)');
        case 153:
            // Le lecteur n'a pas reçu l'origine de la page (en-tête Referer) : touche toutes les vidéos.
            return new PlayerError('init', 'YouTube refuse le lecteur intégré (erreur 153 : origine de la page absente)');
        default:
            return new PlayerError('playback', `Erreur du lecteur YouTube (${code})`);
    }
}
