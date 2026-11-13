// resources/js/hooks/usePlaybackPosition.ts — position de lecture animée, recalculée à chaque image.
import { computePositionMs } from '@/lib/time';
import type { PlaybackState } from '@/types';
import { useEffect, useState } from 'react';

/**
 * Renvoie la position de lecture courante (ms), rafraîchie en continu pendant la lecture.
 *
 * requestAnimationFrame plutôt que setInterval : calé sur l'affichage (barre fluide), et suspendu
 * par le navigateur quand l'onglet est caché (aucun calcul inutile). Comme la position est
 * RECALCULÉE depuis l'horloge à chaque image, cette suspension ne crée aucune dérive.
 */
export function usePlaybackPosition(playback: PlaybackState, clockOffsetMs: number): number {
    const [positionMs, setPositionMs] = useState<number>(() => computePositionMs(playback, clockOffsetMs));

    useEffect(() => {
        // Recalage immédiat à chaque nouvel état (pause, seek, changement de morceau).
        setPositionMs(computePositionMs(playback, clockOffsetMs));

        if (playback.status !== 'playing') {
            return;
        }

        let frame = requestAnimationFrame(function tick() {
            setPositionMs(computePositionMs(playback, clockOffsetMs));
            frame = requestAnimationFrame(tick);
        });

        return () => cancelAnimationFrame(frame);
    }, [playback, clockOffsetMs]);

    return positionMs;
}
