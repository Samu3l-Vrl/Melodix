// resources/js/Components/ProviderBadge.tsx — pastille indiquant la source d'un morceau (Spotify ou YouTube).
import type { Provider } from '@/types';

const LABELS: Record<Provider, string> = {
    spotify: 'Spotify',
    youtube: 'YouTube',
};

// Classes écrites en toutes lettres : Tailwind ne détecte pas les noms de classes construits dynamiquement.
const STYLES: Record<Provider, { badge: string; dot: string }> = {
    spotify: { badge: 'bg-spotify/15 text-spotify ring-spotify/30', dot: 'bg-spotify' },
    youtube: { badge: 'bg-youtube/15 text-red-300 ring-youtube/40', dot: 'bg-youtube' },
};

interface ProviderBadgeProps {
    provider: Provider;
    className?: string;
}

export default function ProviderBadge({ provider, className = '' }: ProviderBadgeProps) {
    const style = STYLES[provider];

    return (
        <span
            data-provider={provider}
            className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[0.7rem] font-semibold uppercase tracking-wide ring-1 ring-inset ${style.badge} ${className}`}
        >
            <span aria-hidden="true" className={`h-1.5 w-1.5 rounded-full ${style.dot}`} />
            {LABELS[provider]}
        </span>
    );
}
