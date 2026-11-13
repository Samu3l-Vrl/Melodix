// tailwind.config.ts — thème Tailwind : palette sombre "soirée" et points de rupture pour TV / ultrawide.
import type { Config } from 'tailwindcss';

export default {
    content: ['./resources/views/**/*.blade.php', './resources/js/**/*.{ts,tsx}'],
    theme: {
        extend: {
            colors: {
                // Fond très sombre légèrement violacé : lisible de loin, peu éblouissant dans une pièce sombre.
                ink: {
                    950: '#0b0b12',
                    900: '#12121c',
                    800: '#1b1b29',
                    700: '#28283a',
                    600: '#3a3a52',
                },
                accent: {
                    DEFAULT: '#a78bfa',
                    strong: '#8b5cf6',
                },
                spotify: '#1ed760',
                youtube: '#ff0033',
            },
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                // Code du salon : chasse fixe pour que les 4 lettres restent bien détachées.
                code: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
            },
            screens: {
                // Écrans 21:9 et plus : on passe à trois colonnes au lieu d'étirer deux colonnes.
                ultrawide: { raw: '(min-aspect-ratio: 21/9) and (min-width: 1280px)' },
            },
        },
    },
    plugins: [],
} satisfies Config;
