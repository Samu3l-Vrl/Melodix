// resources/js/hooks/useDebouncedValue.ts — renvoie une valeur seulement une fois qu'elle a cessé de changer.
import { useEffect, useState } from 'react';

/**
 * Tant que `value` change plus vite que `delayMs`, l'ancienne valeur est conservée.
 * Utilisé pour la recherche : "daft punk" tapé d'une traite ne déclenche qu'UNE requête,
 * au lieu de neuf (chaque recherche YouTube coûte 100 unités de quota).
 */
export function useDebouncedValue<T>(value: T, delayMs: number): T {
    const [debounced, setDebounced] = useState<T>(value);

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delayMs);

        // Nouvelle frappe avant l'échéance : on annule la minuterie précédente.
        return () => clearTimeout(timer);
    }, [value, delayMs]);

    return debounced;
}
