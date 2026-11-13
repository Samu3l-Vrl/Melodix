// resources/js/lib/time.ts — calculs de temps : position de lecture extrapolée et formatage des durées.
import type { PlaybackState } from '@/types';

/**
 * Position de lecture "maintenant", déduite du point de référence envoyé par le serveur.
 *
 * @param playback      État de lecture (position_ms à l'instant position_updated_at).
 * @param clockOffsetMs Décalage horloge serveur - horloge locale (voir useRoomState).
 * @param nowMs         Horloge locale (injectable pour les tests).
 *
 * Pourquoi ne pas simplement incrémenter un compteur local ? Parce qu'un onglet en arrière-plan
 * ou un téléphone en veille gèle les timers : en recalculant à partir de l'horloge, la barre
 * est juste dès le réveil, sans dérive accumulée.
 */
export function computePositionMs(playback: PlaybackState, clockOffsetMs: number, nowMs: number = Date.now()): number {
    const duration = playback.current?.track.duration_ms ?? 0;

    if (playback.status !== 'playing' || playback.position_updated_at === null) {
        return clamp(playback.position_ms, 0, duration);
    }

    // "Maintenant" exprimé dans l'horloge du serveur.
    const serverNow = nowMs + clockOffsetMs;
    const elapsed = Math.max(0, serverNow - playback.position_updated_at);

    return clamp(playback.position_ms + elapsed, 0, duration);
}

/** 253000 -> "4:13" ; 3723000 -> "1:02:03". */
export function formatDuration(ms: number): string {
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const pad = (value: number): string => value.toString().padStart(2, '0');

    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${minutes}:${pad(seconds)}`;
}

export function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), Math.max(min, max));
}
