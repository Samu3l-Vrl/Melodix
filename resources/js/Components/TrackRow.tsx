// resources/js/Components/TrackRow.tsx — ligne d'un morceau : pochette, titre, artiste, source, durée, auteur, actions.
// Utilisée partout : résultats de recherche, file de l'hôte, file en lecture seule de l'invité.
import ProviderBadge from '@/Components/ProviderBadge';
import { formatDuration } from '@/lib/time';
import type { AddedBy, SearchResult } from '@/types';
import { Music } from 'lucide-react';
import type { ReactNode } from 'react';

interface TrackRowProps {
    /** Un `Track` complet convient aussi (il a tous les champs d'un SearchResult). */
    track: SearchResult;
    /** Rang affiché à gauche (1 = prochain morceau). */
    index?: number;
    /** Qui a ajouté le morceau. */
    addedBy?: AddedBy;
    /** Boutons affichés à droite (ajouter, retirer, monter, descendre…). */
    actions?: ReactNode;
    /** Taille "lg" pour l'écran TV, lisible de loin. */
    size?: 'md' | 'lg';
    className?: string;
}

export default function TrackRow({ track, index, addedBy, actions, size = 'md', className = '' }: TrackRowProps) {
    const large = size === 'lg';

    return (
        <div className={`flex min-w-0 items-center gap-3 ${className}`}>
            {index !== undefined && (
                <span className={`tabular w-6 shrink-0 text-center font-semibold text-slate-500 ${large ? 'text-lg' : 'text-sm'}`}>
                    {index}
                </span>
            )}

            <div className={`shrink-0 overflow-hidden rounded-md bg-ink-700 ${large ? 'h-14 w-14' : 'h-12 w-12'}`}>
                {track.album_art_url ? (
                    // alt vide : image décorative, le titre est déjà lu juste à côté.
                    <img src={track.album_art_url} alt="" loading="lazy" className="h-full w-full object-cover" />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-slate-500">
                        <Music aria-hidden="true" className="h-5 w-5" />
                    </div>
                )}
            </div>

            {/* min-w-0 : autorise la troncature du texte dans un conteneur flex. */}
            <div className="min-w-0 flex-1">
                <p className={`truncate font-semibold text-slate-100 ${large ? 'text-lg' : 'text-sm'}`}>{track.title}</p>
                <p className={`truncate text-slate-400 ${large ? 'text-base' : 'text-xs'}`}>{track.artist}</p>
                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400">
                    <ProviderBadge provider={track.provider} />
                    <span className="tabular">{formatDuration(track.duration_ms)}</span>
                    {addedBy && (
                        <span className="truncate">
                            ajouté par <span className="font-medium text-slate-200">{addedBy.name}</span>
                            {addedBy.type === 'host' && ' (hôte)'}
                        </span>
                    )}
                </div>
            </div>

            {actions && <div className="flex shrink-0 items-center gap-1">{actions}</div>}
        </div>
    );
}
