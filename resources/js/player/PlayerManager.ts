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

export class PlayerManager {
    private readonly adapters = new Map<Provider, PlayerAdapter>();
    private active: PlayerAdapter | null = null;
    private current: CurrentTrack | null = null;
    /** Clé du dernier morceau déjà signalé terminé ou en erreur : un seul signal par morceau. */
    private settledKey: number | null = null;
    /** Numéro du dernier appel à load() : permet d'ignorer le résultat d'un chargement devenu obsolète. */
    private loadSequence = 0;

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
        await this.active?.pause().catch(() => undefined);
    }

    async seek(positionMs: number): Promise<void> {
        await this.active?.seek(positionMs).catch(() => undefined);
    }

    /** Plus rien à jouer : on met le lecteur actif en pause et on oublie le morceau courant. */
    async stop(): Promise<void> {
        this.loadSequence++;
        this.current = null;
        await this.active?.pause().catch(() => undefined);
    }

    /** Clé du morceau actuellement chargé (null si aucun). */
    get currentKey(): number | null {
        return this.current?.key ?? null;
    }

    destroy(): void {
        this.loadSequence++;
        this.current = null;
        this.active = null;

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
                this.events.onProgress?.(this.current.key, positionMs, durationMs);
            }
        });

        this.adapters.set(provider, adapter);

        return adapter;
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
