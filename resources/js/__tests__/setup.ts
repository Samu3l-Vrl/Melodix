// resources/js/__tests__/setup.ts — initialisation commune à tous les tests Vitest.
import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

afterEach(() => {
    // Démonte les composants rendus et vide le stockage : aucun test ne dépend du précédent.
    cleanup();
    window.localStorage.clear();
});
