// resources/js/player/loadScript.ts — chargement unique d'un script externe (SDK Spotify, API YouTube).
import { PlayerError } from '@/player/PlayerAdapter';

const pending = new Map<string, Promise<void>>();

/**
 * Injecte une balise <script> et renvoie une promesse résolue à son chargement.
 * Les appels suivants pour la même URL renvoient la même promesse : le script n'est jamais chargé deux fois.
 */
export function loadScript(src: string): Promise<void> {
    const existing = pending.get(src);

    if (existing) {
        return existing;
    }

    const promise = new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');

        script.src = src;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            // On oublie l'échec pour permettre une nouvelle tentative (bloqueur de pub désactivé, réseau revenu…).
            pending.delete(src);
            script.remove();
            reject(new PlayerError('init', `Impossible de charger ${src}`));
        };

        document.head.appendChild(script);
    });

    pending.set(src, promise);

    return promise;
}
