// resources/js/app.tsx — point d'entrée du frontend : démarre Inertia et monte la page React demandée.
import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME ?? 'Jukebox';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    // Le serveur renvoie un nom de page ("Host/HostView") ; on charge le fichier correspondant.
    // import.meta.glob crée un fichier JS par page : le téléphone d'un invité ne télécharge
    // jamais le code de l'écran hôte (SDK Spotify, lecteur YouTube, QR code…).
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx')),
    setup({ el, App, props }) {
        // Rendu côté client uniquement (SSR désactivé) : createRoot, pas hydrateRoot.
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#a78bfa',
    },
});
