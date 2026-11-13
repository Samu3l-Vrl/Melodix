// resources/js/player/PlayerManager.ts — chef d'orchestre des lecteurs sur l'écran hôte.
// Choisit l'adaptateur selon `track.provider`, garantit qu'un seul lecteur joue à la fois,
// et remonte "morceau terminé" / "morceau en erreur" pour que le salon passe au suivant.
import { PlayerError, type LoadOptions, type PlayerAdapter } from '@/player/PlayerAdapter';
import type { Provider, Track } from '@/types';

/** Fabrique d'adaptateur : l'instance n'est créée qu'au premier morceau de cette source. */
export type AdapterFactory = () => PlayerAdapter;

export interface PlayerManagerEvents {
    /**
     * Le morceau identifié par `key` est arrivé à sa fin : il faut passer au suivant.
     * (La page hôte le signale au serveur, qui décide du morceau suivant et le diffuse.)
     */
    onTrackEnded: (key: number) => void;
    /**
     * Le morceau `key` est illisible (vidéo non intégrable, morceau indisponible, source absente…) :
     * il faut le sauter automatiquement plutôt que de bloquer la soirée.
     */
    onTrackError: (key: number, error: PlayerError) => void;
    /** Position réelle du lecteur actif (pour détecter une dérive par rapport au serveur). */
    onProgress?: (key: number, positionMs: number, durationMs: number) => void;
}

interface CurrentTrack {
    /** Identifiant de l'élément de file (QueueItem.id) : distingue deux passages du même morceau. */
    key: number;
    track: Track;
}

/** Fréquence de réglage du volume pendant une transition. */
const TRANSITION_TICK_MS = 100;
/** Le morceau suivant est lancé quand il reste moins de 300 ms (le courant est alors inaudible). */
const EARLY_END_MS = 300;
/** Montée en volume maximale d'un morceau qui commence. */
const MAX_FADE_IN_MS = 4000;
/** Au-delà, une position non confirmée par le lecteur n'est plus extrapolée. */
const MAX_EXTRAPOLATION_MS = 1500;
/** Écart minimal entre deux volumes appliqués (évite des appels inutiles au lecteur). */
const VOLUME_STEP = 0.02;

export class PlayerManager {
    private readonly adapters = new Map<Provider, PlayerAdapter>();
    private active: PlayerAdapter | null = null;
    private current: CurrentTrack | null = null;
    /** Clé du dernier morceau déjà signalé terminé ou en erreur : un seul signal par morceau. */
    private settledKey: number | null = null;
    /** Numéro du dernier appel à load() : permet d'ignorer le résultat d'un chargement devenu obsolète. */
    private loadSequence = 0;

    // --- Transitions entre morceaux -------------------------------------------------------
    /** Durée du fondu de fin (ms). 0 = pas de transition. */
    private transitionMs = 0;
    /** Le lecteur actif est-il censé jouer (et non en pause) ? */
    private playing = false;
    /** Dernière position réelle remontée par le lecteur actif, et l'instant où elle l'a été. */
    private lastProgress: { key: number; positionMs: number; at: number } | null = null;
    /** Dernier volume appliqué au lecteur actif (évite d'appeler setVolume 10 fois par seconde pour rien). */
    private appliedVolume: number | null = null;
    private transitionTimer: ReturnType<typeof setInterval> | null = null;

    constructor(
        private readonly factories: Partial<Record<Provider, AdapterFactory>>,
        private readonly events: PlayerManagerEvents,
    ) {}

    /** Sources que ce gestionnaire sait lire. */
    supports(provider: Provider): boolean {
        return this.factories[provider] !== undefined;
    }

    /**
     * À appeler dans le clic "Démarrer la session" : crée tous les adaptateurs et les laisse
     * s'initialiser pendant que le geste utilisateur autorise l'audio. Un échec ici n'est pas
     * bloquant (il sera signalé au premier morceau concerné).
     */
    async unlock(): Promise<void> {
        const preparations = (Object.keys(this.factories) as Provider[]).map(async (provider) => {
            try {
                await this.adapterFor(provider)?.prepare?.();
            } catch {
                // Volontairement ignoré : voir commentaire ci-dessus.
            }
        });

        await Promise.all(preparations);
    }

    /**
     * Charge un morceau avec l'adaptateur de sa source.
     * Ne rejette jamais : toute erreur est convertie en `onTrackError` (saut automatique).
     */
    async load(key: number, track: Track, options: LoadOptions): Promise<void> {
        const sequence = ++this.loadSequence;
        const adapter = this.adapterFor(track.provider);

        this.current = { key, track };
        this.settledKey = null;
        this.playing = options.autoplay;
        this.lastProgress = null;

        // Bascule de source : le lecteur précédent doit se taire avant que le nouveau démarre.
        if (this.active !== null && this.active !== adapter) {
            await this.active.pause().catch(() => undefined);
        }

        if (adapter === null) {
            this.active = null;
            this.fail(key, new PlayerError('provider_unavailable', `Aucun lecteur disponible pour ${track.provider}`));

            return;
        }

        this.active = adapter;
        // Avec les transitions, un morceau qui démarre au début part du silence puis monte (voir tick()).
        // Sinon (ou en reprise en cours de morceau), plein volume.
        await this.applyVolume(this.transitionMs > 0 && options.positionMs < this.fadeInMs() ? 0 : 1, true);

        try {
            await adapter.load(track, options);
        } catch (error) {
            // Si un autre load() a été lancé entre-temps, cet échec concerne un morceau déjà remplacé.
            if (sequence === this.loadSequence) {
                this.fail(key, toPlayerError(error));
            }
        }
    }

    /** Reprend la lecture. Un échec ici signifie que le morceau est illisible : il sera sauté. */
    async play(): Promise<void> {
        this.playing = true;

        try {
            await this.active?.play();
        } catch (error) {
            if (this.current !== null) {
                this.fail(this.current.key, toPlayerError(error));
            }
        }
    }

    /** Pause et seek sont "au mieux" : un échec ne doit ni planter l'interface ni sauter le morceau. */
    async pause(): Promise<void> {
        this.playing = false;
        await this.active?.pause().catch(() => undefined);
    }

    async seek(positionMs: number): Promise<void> {
        await this.active?.seek(positionMs).catch(() => undefined);
    }

    /** Plus rien à jouer : on met le lecteur actif en pause et on oublie le morceau courant. */
    async stop(): Promise<void> {
        this.loadSequence++;
        this.current = null;
        this.playing = false;
        await this.active?.pause().catch(() => undefined);
    }

    /** Clé du morceau actuellement chargé (null si aucun). */
    get currentKey(): number | null {
        return this.current?.key ?? null;
    }

    /**
     * Règle la transition entre morceaux : pendant ses `ms` dernières millisecondes, le morceau baisse
     * jusqu'au silence ; le suivant est lancé juste avant la fin et monte en volume.
     *
     * Pas de chevauchement : Spotify ne joue qu'un morceau à la fois par compte, et YouTube exige un
     * lecteur visible. Le fondu masque en revanche le silence de chargement entre deux morceaux.
     */
    setTransition(ms: number): void {
        this.transitionMs = Math.max(0, ms);

        if (this.transitionMs === 0) {
            if (this.transitionTimer !== null) {
                clearInterval(this.transitionTimer);
                this.transitionTimer = null;
            }

            // Plus de fondu : tous les lecteurs reviennent au plein volume.
            this.appliedVolume = null;

            for (const adapter of this.adapters.values()) {
                void adapter.setVolume(1).catch(() => undefined);
            }

            return;
        }

        this.transitionTimer ??= setInterval(() => this.tick(), TRANSITION_TICK_MS);
    }

    destroy(): void {
        this.loadSequence++;
        this.current = null;
        this.active = null;

        if (this.transitionTimer !== null) {
            clearInterval(this.transitionTimer);
            this.transitionTimer = null;
        }

        for (const adapter of this.adapters.values()) {
            adapter.destroy();
        }

        this.adapters.clear();
    }

    /** Renvoie (en le créant au besoin) l'adaptateur d'une source, ou null si elle n'est pas disponible. */
    private adapterFor(provider: Provider): PlayerAdapter | null {
        const existing = this.adapters.get(provider);

        if (existing) {
            return existing;
        }

        const factory = this.factories[provider];

        if (!factory) {
            return null;
        }

        const adapter = factory();

        // Les events d'un adaptateur ne comptent que s'il est l'adaptateur ACTIF : un lecteur mis en
        // pause lors d'une bascule peut encore émettre, et ne doit pas faire avancer la file.
        adapter.onEnded(() => {
            if (this.active === adapter && this.current !== null) {
                const { key } = this.current;

                this.settle(key, () => this.events.onTrackEnded(key));
            }
        });

        adapter.onError((error) => {
            if (this.active === adapter && this.current !== null) {
                this.fail(this.current.key, error);
            }
        });

        adapter.onProgress((positionMs, durationMs) => {
            if (this.active === adapter && this.current !== null) {
                this.lastProgress = { key: this.current.key, positionMs, at: Date.now() };
                this.events.onProgress?.(this.current.key, positionMs, durationMs);
            }
        });

        this.adapters.set(provider, adapter);

        return adapter;
    }

    /**
     * Appelé toutes les 100 ms quand les transitions sont actives : ajuste le volume du lecteur
     * et lance le morceau suivant juste avant la fin du morceau courant.
     *
     * La position est celle REMONTÉE PAR LE LECTEUR (extrapolée entre deux relevés), pas celle du
     * serveur : pendant une publicité YouTube ou une mise en mémoire tampon, la position réelle
     * n'avance pas, et le fondu ne doit pas commencer. La durée est celle du catalogue (track.duration_ms),
     * jamais celle annoncée par le lecteur, qui peut être celle d'une publicité.
     */
    private tick(): void {
        const current = this.current;
        const progress = this.lastProgress;

        if (this.transitionMs === 0 || !this.playing || current === null || this.active === null) {
            return;
        }

        if (progress === null || progress.key !== current.key) {
            return;
        }

        // Extrapolation bornée : si le lecteur ne remonte plus rien (blocage), on n'invente pas la suite.
        const elapsed = Math.min(Math.max(0, Date.now() - progress.at), MAX_EXTRAPOLATION_MS);
        const positionMs = progress.positionMs + elapsed;
        const remainingMs = current.track.duration_ms - positionMs;

        const fadeIn = this.fadeInMs();
        const volumeIn = fadeIn > 0 ? positionMs / fadeIn : 1;
        const volumeOut = remainingMs / this.transitionMs;

        void this.applyVolume(Math.min(1, Math.max(0, Math.min(volumeIn, volumeOut))));

        // Fin anticipée : le morceau est déjà silencieux, on enchaîne sans attendre la fin « officielle »
        // (ni le temps de chargement qui suivrait). Un seul signal par morceau, grâce à settle().
        if (remainingMs <= EARLY_END_MS) {
            this.playing = false;
            this.settle(current.key, () => this.events.onTrackEnded(current.key));
        }
    }

    /** Durée de la montée en volume d'un morceau : la moitié de la transition, 4 s au plus. */
    private fadeInMs(): number {
        return Math.min(this.transitionMs / 2, MAX_FADE_IN_MS);
    }

    private async applyVolume(volume: number, force = false): Promise<void> {
        const rounded = Math.round(volume * 100) / 100;

        if (this.active === null || (!force && this.appliedVolume !== null && Math.abs(rounded - this.appliedVolume) < VOLUME_STEP)) {
            return;
        }

        this.appliedVolume = rounded;
        await this.active.setVolume(rounded).catch(() => undefined);
    }

    private fail(key: number, error: PlayerError): void {
        this.settle(key, () => this.events.onTrackError(key, error));
    }

    /** Garantit au plus UN signal (fin ou erreur) par morceau chargé. */
    private settle(key: number, notify: () => void): void {
        if (this.settledKey === key) {
            return;
        }

        this.settledKey = key;
        notify();
    }
}

function toPlayerError(error: unknown): PlayerError {
    if (error instanceof PlayerError) {
        return error;
    }

    return new PlayerError('playback', error instanceof Error ? error.message : 'Erreur de lecture inconnue');
}
