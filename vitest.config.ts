// vitest.config.ts — configuration des tests frontend (Vitest + React Testing Library).
// Fichier séparé de vite.config.ts : les tests n'ont pas besoin du plugin Laravel (manifest, hot file…).
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        // jsdom simule un navigateur (DOM, localStorage) dans Node.
        environment: 'jsdom',
        // describe / it / expect / vi disponibles sans import (types déclarés dans tsconfig.json).
        globals: true,
        setupFiles: ['./resources/js/__tests__/setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
        // Chaque test repart de mocks vierges.
        restoreMocks: true,
    },
});
