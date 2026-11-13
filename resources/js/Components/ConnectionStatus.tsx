// resources/js/Components/ConnectionStatus.tsx — indicateur de l'état de la connexion temps réel.
import type { ConnectionState } from '@/types';
import { LoaderCircle, Wifi, WifiOff } from 'lucide-react';

const CONFIG: Record<ConnectionState, { label: string; className: string }> = {
    connected: { label: 'Connecté', className: 'text-emerald-300' },
    reconnecting: { label: 'Reconnexion…', className: 'text-amber-300' },
    offline: { label: 'Hors ligne', className: 'text-rose-300' },
};

interface ConnectionStatusProps {
    state: ConnectionState;
    /** Version compacte (icône seule, libellé pour lecteurs d'écran) pour l'en-tête mobile. */
    compact?: boolean;
    className?: string;
}

export default function ConnectionStatus({ state, compact = false, className = '' }: ConnectionStatusProps) {
    const { label, className: color } = CONFIG[state];

    return (
        // role="status" : le changement d'état est annoncé par les lecteurs d'écran sans voler le focus.
        <span
            role="status"
            data-state={state}
            title={label}
            className={`inline-flex items-center gap-1.5 text-sm font-medium ${color} ${className}`}
        >
            {state === 'connected' && <Wifi aria-hidden="true" className="h-4 w-4" />}
            {state === 'reconnecting' && <LoaderCircle aria-hidden="true" className="h-4 w-4 animate-spin" />}
            {state === 'offline' && <WifiOff aria-hidden="true" className="h-4 w-4" />}
            <span className={compact ? 'sr-only' : ''}>{label}</span>
        </span>
    );
}
