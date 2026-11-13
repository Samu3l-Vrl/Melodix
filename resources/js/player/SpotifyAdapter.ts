// resources/js/player/SpotifyAdapter.ts — lecture Spotify dans le navigateur de l'hôte via le Web Playback SDK.
// Le SDK crée un "appareil" Spotify Connect dans l'onglet ; on y lance un morceau avec l'API Web
// (PUT /v1/me/player/play). Nécessite un compte Premium et le scope `streaming`.
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

const SDK_URL = 'https://sdk.scdn.co/spotify-player.js';
const PLAYER_ENDPOINT = 'https://api.spotify.com/v1/me/player';
const PLAY_ENDPOINT = `${PLAYER_ENDPOINT}/play`;
/** Tentatives de lancement d'un morceau quand Spotify ne trouve pas encore l'appareil (404) ou est surchargé. */
const PLAY_ATTEMPTS = 5;
const PLAY_RETRY_DELAY_MS = 1000;
const PROGRESS_INTERVAL_MS = 500;
/** Délai maximal pour que l'appareil du SDK soit prêt. */
const READY_TIMEOUT_MS = 15000;
/** Un morceau est considéré "allé au bout" si la dernière position connue est à moins de 3 s de la fin. */
const END_TOLERANCE_MS = 3000;

export interface SpotifyAdapterOptions {
    /** Fournit un jeton d'accès valide (le serveur le rafraîchit si besoin) : GET /host/spotify/token. */
    getToken: () => Promise<string>;
    /** Nom de l'appareil visible dans les applications Spotify de l'hôte. */
    deviceName: string;
}

let sdkReady: Promise<void> | null = null;

/** Charge le SDK une seule fois et attend son callback global `onSpotifyWebPlaybackSDKReady`. */
function loadSdk(): Promise<void> {
    if (window.Spotify?.Player) {
        return Promise.resolve();
    }

    sdkReady ??= new Promise<void>((resolve, reject) => {
        // Le SDK exige que ce callback existe AVANT la fin du chargement du script.
        window.onSpotifyWebPlaybackSDKReady = () => resolve();

        loadScript(SDK_URL).catch((error: unknown) => {
            sdkReady = null;
            reject(error instanceof Error ? error : new PlayerError('init', 'SDK Spotify indisponible'));
        });
    });

    return sdkReady;
}

export class SpotifyAdapter implements PlayerAdapter {
    readonly provider = 'spotify' as const;

    private player: Spotify.Player | null = null;
    private deviceReady: Promise<string> | null = null;
    private progressTimer: ReturnType<typeof setInterval> | null = null;

    // Suivi du morceau chargé, pour détecter sa fin (le SDK n'a pas d'event "ended").
    private loadedUri: string | null = null;
    private hasStarted = false;
    private endedEmitted = false;
    private lastPositionMs = 0;
    private lastDurationMs = 0;

    private readonly progress = new Emitter<[number, number]>();
    private readonly ended = new Emitter<[]>();
    private readonly errors = new Emitter<[PlayerError]>();

    constructor(private readonly options: SpotifyAdapterOptions) {}

    async prepare(): Promise<void> {
        // Appelé pendant le clic "Démarrer la session" : activateElement() rattache l'audio à ce geste
        // utilisateur, sans quoi certains navigateurs bloquent la lecture automatique.
        const ready = this.ensureDevice();
        await this.player?.activateElement();
        const deviceId = await ready;

        // On désigne tout de suite l'onglet comme appareil actif, sans lancer de son : Spotify finit ainsi
        // d'enregistrer l'appareil avant le premier morceau. Échec sans conséquence (load() réessaie).
        try {
            await fetch(PLAYER_ENDPOINT, {
                method: 'PUT',
                headers: { Authorization: `Bearer ${await this.options.getToken()}`, 'Content-Type': 'application/json' },
                body: JSON.stringify({ device_ids: [deviceId], play: false }),
            });
        } catch {
            // Volontairement ignoré.
        }
    }

    async load(track: Track, options: LoadOptions): Promise<void> {
        const deviceId = await this.ensureDevice();
        const uri = `spotify:track:${track.provider_track_id}`;

        this.loadedUri = uri;
        this.hasStarted = false;
        this.endedEmitted = false;
        this.lastPositionMs = options.positionMs;
        this.lastDurationMs = track.duration_ms;

        await this.startPlayback(deviceId, uri, options.positionMs);

        if (!options.autoplay) {
            // L'API Web n'a pas de "charger sans jouer" : on met en pause aussitôt.
            await this.player?.pause();
        }

        this.startProgress();
    }

    /**
     * Lance le morceau sur l'appareil de l'onglet via l'API Web.
     *
     * Juste après l'événement `ready`, Spotify répond souvent 404 (« Device not found ») pendant
     * quelques secondes, le temps d'enregistrer l'appareil. Ce n'est pas un morceau illisible :
     * on réessaie, comme pour une surcharge passagère (429, 5xx). Sans ces nouvelles tentatives,
     * le premier morceau de la soirée était sauté au bout d'une seconde.
     */
    private async startPlayback(deviceId: string, uri: string, positionMs: number): Promise<void> {
        let lastStatus = 0;

        for (let attempt = 1; attempt <= PLAY_ATTEMPTS; attempt++) {
            const token = await this.options.getToken();

            // `uris` avec un seul morceau : pas de contexte (album/playlist), donc rien ne s'enchaîne après.
            // C'est le serveur, pas Spotify, qui décide du morceau suivant.
            const response = await fetch(`${PLAY_ENDPOINT}?device_id=${encodeURIComponent(deviceId)}`, {
                method: 'PUT',
                headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
                body: JSON.stringify({ uris: [uri], position_ms: Math.max(0, Math.floor(positionMs)) }),
            });

            if (response.ok) {
                return;
            }

            lastStatus = response.status;

            const retryable = lastStatus === 404 || lastStatus === 429 || lastStatus === 401 || lastStatus >= 500;

            if (!retryable || attempt === PLAY_ATTEMPTS) {
                break;
            }

            const retryAfter = Number(response.headers.get('Retry-After'));
            await wait(Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter * 1000 : PLAY_RETRY_DELAY_MS * attempt);
        }

        throw toPlaybackError(lastStatus);
    }

    async play(): Promise<void> {
        await this.player?.resume();
    }

    async pause(): Promise<void> {
        await this.player?.pause();
    }

    async seek(positionMs: number): Promise<void> {
        await this.player?.seek(Math.max(0, Math.floor(positionMs)));
    }

    destroy(): void {
        this.stopProgress();
        this.player?.disconnect();
        this.player = null;
        this.deviceReady = null;
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

    /** Crée le lecteur, le connecte et attend l'identifiant de son appareil Spotify Connect. */
    private ensureDevice(): Promise<string> {
        this.deviceReady ??= this.connect().catch((error: unknown) => {
            // Échec d'initialisation : on permettra une nouvelle tentative au prochain morceau.
            this.deviceReady = null;
            throw error;
        });

        return this.deviceReady;
    }

    private async connect(): Promise<string> {
        await loadSdk();

        const SpotifySdk = window.Spotify;

        if (!SpotifySdk) {
            throw new PlayerError('init', 'SDK Spotify indisponible');
        }

        const player = new SpotifySdk.Player({
            name: this.options.deviceName,
            getOAuthToken: (callback) => {
                // Le SDK rappelle cette fonction à chaque expiration : le serveur fournit toujours un jeton frais.
                this.options.getToken().then(callback, () => {
                    this.errors.emit(new PlayerError('auth', 'Session Spotify expirée : reconnectez-vous'));
                });
            },
            volume: 1,
        });

        this.player = player;

        return new Promise<string>((resolve, reject) => {
            const timeout = setTimeout(
                () => reject(new PlayerError('init', "Le lecteur Spotify n'a pas démarré à temps")),
                READY_TIMEOUT_MS,
            );
            const fail = (code: 'init' | 'auth', message: string): void => {
                clearTimeout(timeout);
                reject(new PlayerError(code, message));
            };

            player.addListener('ready', ({ device_id }) => {
                clearTimeout(timeout);
                resolve(device_id);
            });
            // Navigateur non supporté (pas d'EME/DRM) ou contexte non sécurisé (http hors 127.0.0.1).
            player.addListener('initialization_error', ({ message }) => fail('init', message));
            player.addListener('authentication_error', ({ message }) => fail('auth', message));
            // Compte non Premium.
            player.addListener('account_error', ({ message }) => fail('auth', message));
            player.addListener('playback_error', ({ message }) => this.errors.emit(new PlayerError('playback', message)));
            player.addListener('player_state_changed', (state) => this.handleState(state));

            player.connect().then((connected) => {
                if (!connected) {
                    fail('init', 'Connexion au lecteur Spotify refusée');
                }
            }, () => fail('init', 'Connexion au lecteur Spotify impossible'));
        });
    }

    /**
     * Le SDK n'émet pas d'event de fin. Quand un morceau lancé seul se termine, le lecteur repasse
     * en pause à la position 0. Cet état existe aussi, brièvement, au chargement ou pendant une
     * mise en mémoire tampon : on n'en conclut donc à une fin QUE si la lecture a vraiment commencé
     * ET si la dernière position connue était à moins de 3 s de la fin du morceau.
     * (La position est relevée à chaque event ET toutes les 500 ms par startProgress.)
     */
    private handleState(state: Spotify.WebPlaybackState | null): void {
        if (!state || this.loadedUri === null || state.track_window.current_track.uri !== this.loadedUri) {
            return;
        }

        if (!state.paused && state.position > 0) {
            this.hasStarted = true;
        }

        if (!state.paused || state.position > 0) {
            this.lastPositionMs = state.position;
            this.lastDurationMs = state.duration || this.lastDurationMs;
        }

        const wentThrough = this.lastDurationMs > 0 && this.lastPositionMs >= this.lastDurationMs - END_TOLERANCE_MS;
        const finished = this.hasStarted && state.paused && state.position === 0 && wentThrough;

        if (finished && !this.endedEmitted) {
            this.endedEmitted = true;
            this.stopProgress();
            this.ended.emit();
        }
    }

    private startProgress(): void {
        if (this.progressTimer !== null) {
            return;
        }

        this.progressTimer = setInterval(() => {
            void this.player?.getCurrentState().then((state) => {
                // Même analyse que pour un event : tient la dernière position à jour pour la détection de fin.
                this.handleState(state);

                if (state && !state.paused) {
                    this.progress.emit(state.position, state.duration);
                }
            });
        }, PROGRESS_INTERVAL_MS);
    }

    private stopProgress(): void {
        if (this.progressTimer !== null) {
            clearInterval(this.progressTimer);
            this.progressTimer = null;
        }
    }
}

const wait = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

/** Traduit le dernier refus de l'API Web en erreur commune, avec un message utile à l'hôte. */
function toPlaybackError(status: number): PlayerError {
    switch (status) {
        case 401:
            return new PlayerError('auth', 'Session Spotify expirée : reconnectez-vous depuis l\'accueil');
        case 403:
            return new PlayerError('auth', 'Spotify refuse la lecture (compte Premium requis)');
        case 404:
            return new PlayerError('init', 'Spotify ne trouve pas le lecteur de ce navigateur (HTTP 404)');
        case 429:
            return new PlayerError('playback', 'Spotify limite temporairement les requêtes (HTTP 429)');
        default:
            return new PlayerError('playback', `Spotify a refusé la lecture (HTTP ${status})`);
    }
}
