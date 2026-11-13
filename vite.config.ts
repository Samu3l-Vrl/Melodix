// vite.config.ts — configuration de Vite : compilation React/TypeScript, intégration Laravel, réglages Sail/WSL.
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    // Charge le .env (toutes les variables, pas seulement VITE_*) pour lire le port et l'option de polling.
    const env = loadEnv(mode, process.cwd(), '');
    const port = Number(env.VITE_PORT || 5173);

    return {
        plugins: [
            laravel({
                // Point d'entrée unique ; les pages sont découpées automatiquement (import.meta.glob dans app.tsx).
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                // Recharge le navigateur quand une vue Blade ou une route change.
                refresh: true,
            }),
            react(),
        ],
        resolve: {
            alias: {
                // "@/lib/api" -> resources/js/lib/api (même alias dans tsconfig.json et vitest.config.ts).
                '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            },
        },
        server: {
            // 0.0.0.0 : indispensable dans le conteneur Sail, sinon Vite n'écoute que sur le loopback
            // du conteneur et le navigateur de la machine hôte ne peut pas l'atteindre.
            host: '0.0.0.0',
            port,
            strictPort: true,
            hmr: {
                // Adresse que le NAVIGATEUR utilise pour le rechargement à chaud.
                // WSL2 redirige localhost de Windows vers le conteneur : localhost fonctionne donc des deux côtés.
                host: env.VITE_HMR_HOST || 'localhost',
            },
            watch: {
                // Projet sur un disque Windows monté (/mnt/c/…) : inotify ne remonte pas les changements,
                // il faut scruter les fichiers. Coûteux en CPU : à n'activer que dans ce cas.
                usePolling: env.VITE_USE_POLLING === 'true',
                ignored: ['**/vendor/**', '**/storage/**', '**/node_modules/**'],
            },
        },
    };
});
