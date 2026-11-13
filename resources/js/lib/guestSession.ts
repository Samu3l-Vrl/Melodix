// resources/js/lib/guestSession.ts — persistance de l'identité invité (jeton + pseudo) dans le localStorage.
// Une entrée par salon : rejoindre un autre salon ne fait pas perdre le premier.

export interface GuestSession {
    token: string;
    nickname: string;
}

const key = (code: string): string => `jukebox:guest:${code.toUpperCase()}`;

/** Clé séparée : dernier pseudo utilisé, pour préremplir le formulaire d'un nouveau salon. */
const LAST_NICKNAME_KEY = 'jukebox:last-nickname';

/**
 * Le localStorage peut être indisponible (navigation privée de certains navigateurs, quota plein) :
 * chaque accès est protégé pour que l'application reste utilisable, simplement sans reprise auto.
 */
function safely<T>(action: () => T, fallback: T): T {
    try {
        return action();
    } catch {
        return fallback;
    }
}

export function loadGuestSession(code: string): GuestSession | null {
    return safely(() => {
        const raw = window.localStorage.getItem(key(code));

        if (raw === null) {
            return null;
        }

        const parsed: unknown = JSON.parse(raw);

        // Validation de forme : on ne fait jamais confiance au contenu du stockage.
        if (
            typeof parsed === 'object' &&
            parsed !== null &&
            'token' in parsed &&
            'nickname' in parsed &&
            typeof parsed.token === 'string' &&
            typeof parsed.nickname === 'string'
        ) {
            return { token: parsed.token, nickname: parsed.nickname };
        }

        return null;
    }, null);
}

export function saveGuestSession(code: string, session: GuestSession): void {
    safely(() => {
        window.localStorage.setItem(key(code), JSON.stringify(session));
        window.localStorage.setItem(LAST_NICKNAME_KEY, session.nickname);
    }, undefined);
}

export function clearGuestSession(code: string): void {
    safely(() => window.localStorage.removeItem(key(code)), undefined);
}

export function lastNickname(): string {
    return safely(() => window.localStorage.getItem(LAST_NICKNAME_KEY) ?? '', '');
}
