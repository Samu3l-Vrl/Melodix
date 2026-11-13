// resources/js/player/PlayerAdapter.ts — contrat commun à tous les lecteurs (Spotify, YouTube, demain Deezer…).
// Il n'existe pas de lecture "unifiée" : chaque source impose son propre lecteur dans le navigateur.
// Cette interface est la frontière qui permet au reste de l'application de les piloter de façon identique.
import type { Provider, Track } from '@/types';

/** Catégories d'erreur de lecture, indépendantes de la source. */
export type PlayerErrorCode =
    /** Le morceau ne peut pas être lu (introuvable, intégration interdite, restriction géographique…). */
    | 'unplayable'
    /** Problème d'authentification ou de compte (jeton refusé, compte non Premium). */
    | 'auth'
    /** Le lecteur n'a pas pu s'initialiser (script bloqué, navigateur non supporté). */
    | 'init'
    /** Aucun adaptateur n'est disponible pour cette source dans ce salon. */
    | 'provider_unavailable'
    /** Erreur de lecture non classée. */
    | 'playback';

export class PlayerError extends Error {
    constructor(
        public readonly code: PlayerErrorCode,
        message: string,
    ) {
        super(message);
        this.name = 'PlayerError';
    }
}

export interface LoadOptions {
    /** Position de départ (reprise après rechargement de la page, par exemple). */
    positionMs: number;
    /** false = charger sans lancer (le salon est en pause). */
    autoplay: boolean;
}

export type Unsubscribe = () => void;
export type ProgressListener = (positionMs: number, durationMs: number) => void;
export type EndedListener = () => void;
export type ErrorListener = (error: PlayerError) => void;

export interface PlayerAdapter {
    readonly provider: Provider;

    /**
     * Initialisation facultative, à appeler pendant un geste utilisateur ("Démarrer la session") :
     * chargement du SDK, connexion, déblocage de l'audio.
     */
    prepare?(): Promise<void>;

    /** Charge un morceau à la position donnée. La promesse est rejetée avec PlayerError en cas d'échec. */
    load(track: Track, options: LoadOptions): Promise<void>;

    play(): Promise<void>;
    pause(): Promise<void>;
    seek(positionMs: number): Promise<void>;

    /** Libère toutes les ressources (lecteur, minuteries, écouteurs). */
    destroy(): void;

    /** Position réelle du lecteur, émise régulièrement pendant la lecture. */
    onProgress(listener: ProgressListener): Unsubscribe;
    /** Le morceau chargé est arrivé à sa fin naturelle. */
    onEnded(listener: EndedListener): Unsubscribe;
    /** Erreur survenue APRÈS le chargement (les erreurs de load() passent par le rejet de la promesse). */
    onError(listener: ErrorListener): Unsubscribe;
}

/**
 * Mini-émetteur d'events typé, partagé par les adaptateurs pour implémenter onProgress/onEnded/onError.
 */
export class Emitter<Args extends unknown[]> {
    private listeners = new Set<(...args: Args) => void>();

    subscribe(listener: (...args: Args) => void): Unsubscribe {
        this.listeners.add(listener);

        return () => {
            this.listeners.delete(listener);
        };
    }

    emit(...args: Args): void {
        // Copie : un écouteur peut se désabonner pendant l'émission.
        for (const listener of [...this.listeners]) {
            listener(...args);
        }
    }

    clear(): void {
        this.listeners.clear();
    }
}
