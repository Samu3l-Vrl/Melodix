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
const PLAY_ENDPOINT = 'https://api.spotify.com/v1/me/player/play';
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
        await ready;
    }

    async load(track: Track, options: LoadOptions): Promise<void> {
        const deviceId = await this.ensureDevice();
        const token = await this.options.getToken();
        const uri = `spotify:track:${track.provider_track_id}`;

        this.loadedUri = uri;
        this.hasStarted = false;
        this.endedEmitted = false;
        this.lastPositionMs = options.positionMs;
        this.lastDurationMs = track.duration_ms;

        // `uris` avec un seul morceau : pas de contexte (album/playlist), donc rien ne s'enchaîne après.
        // C'est le serveur, pas Spotify, qui décide du morceau suivant.
        const response = await fetch(`${PLAY_ENDPOINT}?device_id=${encodeURIComponent(deviceId)}`, {
            method: 'PUT',
            headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
            body: JSON.stringify({ uris: [uri], position_ms: Math.max(0, Math.floor(options.positionMs)) }),
        });

        if (!response.ok) {
            throw new PlayerError(
                response.status === 401 || response.status === 403 ? 'auth' : 'unplayable',
                `Spotify a refusé la lecture (HTTP ${response.status})`,
            );
        }

        if (!options.autoplay) {
            // L'API Web n'a pas de "charger sans jouer" : on met en pause aussitôt.
            await this.player?.pause();
        }

        this.startProgress();
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
     * Le SDK n'émet pas d'event de fin. Quand un morceau lancé seul se termine, le lecteur
     * repasse en pause à la position 0 et le morceau apparaît dans `previous_tracks`.
     * On exige en plus que la lecture ait réellement commencé, pour ne pas confondre avec
     * l'état transitoire "en pause à 0" observé juste après le chargement.
     */
    private handleState(state: Spotify.WebPlaybackState | null): void {
        if (!state || this.loadedUri === null) {
            return;
        }

        const { current_track: current, previous_tracks: previous } = state.track_window;

        if (!state.paused && state.position > 0) {
            this.hasStarted = true;
        }

        if (!state.paused || state.position > 0) {
            this.lastPositionMs = state.position;
            this.lastDurationMs = state.duration || this.lastDurationMs;
        }

        const wentThrough = this.lastDurationMs > 0 && this.lastPositionMs >= this.lastDurationMs - END_TOLERANCE_MS;
        const listedAsPrevious = previous.some((track) => track.uri === current.uri || track.id === current.id);
        const finished = this.hasStarted && state.paused && state.position === 0 && (listedAsPrevious || wentThrough);

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
