// resources/js/__tests__/GuestView.test.tsx — télécommande invité : pseudo, debounce de la recherche, états du bouton d'ajout.
import { api, ApiError } from '@/lib/api';
import { saveGuestSession } from '@/lib/guestSession';
import GuestView, { SEARCH_DEBOUNCE_MS } from '@/Pages/Guest/GuestView';
import type { RoomState, SearchResponse } from '@/types';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { makeItem, makeRoomState, makeSearchResult } from './factories';

// Le temps réel n'est pas le sujet de ce fichier : canal muet, connexion "établie".
vi.mock('@/lib/echo', () => ({
    subscribeToRoom: () => () => undefined,
    subscribeToHost: () => () => undefined,
    onConnectionChange: (listener: (state: 'connected') => void) => {
        listener('connected');

        return () => undefined;
    },
}));

// <Head> d'Inertia a besoin du contexte de l'application : remplacé par un composant vide.
vi.mock('@inertiajs/react', () => ({
    Head: (): ReactNode => null,
}));

vi.mock('@/lib/api', async (importOriginal) => {
    const original = await importOriginal<typeof import('@/lib/api')>();

    return {
        ...original,
        api: {
            fetchRoomState: vi.fn(),
            joinRoom: vi.fn(),
            me: vi.fn(),
            search: vi.fn(),
            addToQueue: vi.fn(),
        },
    };
});

const mocked = {
    fetchRoomState: vi.mocked(api.fetchRoomState),
    joinRoom: vi.mocked(api.joinRoom),
    me: vi.mocked(api.me),
    search: vi.mocked(api.search),
    addToQueue: vi.mocked(api.addToQueue),
};

const TOKEN = 'a'.repeat(40);
const oneMoreTime = makeSearchResult({ provider: 'spotify', provider_track_id: 'sp1', title: 'One More Time', artist: 'Daft Punk' });
const aroundTheWorld = makeSearchResult({ provider: 'youtube', provider_track_id: 'yt1', title: 'Around the World', artist: 'Daft Punk' });
const bothSources: SearchResponse = { results: [oneMoreTime, aroundTheWorld], sources: { spotify: 'ok', youtube: 'ok' } };

/** Avance les minuteries simulées et laisse les promesses se résoudre. */
const advance = (ms: number): Promise<void> => act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
});

const type = (value: string): void => {
    fireEvent.change(screen.getByLabelText('Rechercher un morceau'), { target: { value } });
};

/** Rend la page pour un invité déjà identifié (jeton en localStorage, validé par le serveur). */
async function renderAsGuest(room: RoomState = makeRoomState()): Promise<void> {
    saveGuestSession(room.code, { token: TOKEN, nickname: 'Léa' });
    mocked.me.mockResolvedValue({ guest: { id: 1, nickname: 'Léa' } });

    render(<GuestView room={room} />);
    await advance(0);
}

/** Tape une recherche et attend ses résultats. */
async function searchFor(query: string): Promise<void> {
    type(query);
    await advance(SEARCH_DEBOUNCE_MS);
}

beforeEach(() => {
    vi.useFakeTimers();
    Object.values(mocked).forEach((mock) => mock.mockReset());
    // Le resync déclenché à la connexion ne doit pas interférer avec les tests.
    mocked.fetchRoomState.mockImplementation(() => new Promise<RoomState>(() => undefined));
    mocked.search.mockResolvedValue(bothSources);
});

afterEach(() => {
    vi.useRealTimers();
});

describe('GuestView — pseudo', () => {
    it('demande un pseudo à un nouvel invité, puis enregistre le jeton reçu', async () => {
        mocked.joinRoom.mockResolvedValue({ guest: { id: 7, nickname: 'Léa' }, token: TOKEN });
        render(<GuestView room={makeRoomState({ code: 'KZPW' })} />);
        await advance(0);

        expect(mocked.me).not.toHaveBeenCalled();
        expect(screen.queryByLabelText('Rechercher un morceau')).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Votre pseudo'), { target: { value: '  Léa ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Rejoindre la soirée' }));
        await advance(0);

        expect(mocked.joinRoom).toHaveBeenCalledExactlyOnceWith('KZPW', 'Léa');
        expect(screen.getByLabelText('Rechercher un morceau')).toBeInTheDocument();
        expect(JSON.parse(window.localStorage.getItem('jukebox:guest:KZPW') ?? '{}')).toEqual({ token: TOKEN, nickname: 'Léa' });
    });

    it('garde le bouton désactivé tant que le pseudo est vide', async () => {
        render(<GuestView room={makeRoomState()} />);
        await advance(0);

        expect(screen.getByRole('button', { name: 'Rejoindre la soirée' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Votre pseudo'), { target: { value: '   ' } });
        expect(screen.getByRole('button', { name: 'Rejoindre la soirée' })).toBeDisabled();
    });

    it('reprend automatiquement la session quand un jeton valide est stocké', async () => {
        await renderAsGuest(makeRoomState({ code: 'KZPW' }));

        expect(mocked.me).toHaveBeenCalledExactlyOnceWith('KZPW', TOKEN);
        expect(mocked.joinRoom).not.toHaveBeenCalled();
        expect(screen.getByText('Léa')).toBeInTheDocument();
        expect(screen.getByLabelText('Rechercher un morceau')).toBeInTheDocument();
    });

    it('redemande un pseudo (prérempli) quand le jeton stocké est refusé', async () => {
        saveGuestSession('ABCD', { token: TOKEN, nickname: 'Léa' });
        mocked.me.mockRejectedValue(new ApiError('Jeton invité manquant ou invalide.', 401, 'guest_unauthenticated'));

        render(<GuestView room={makeRoomState()} />);
        await advance(0);

        expect(screen.getByLabelText('Votre pseudo')).toHaveValue('Léa');
        expect(window.localStorage.getItem('jukebox:guest:ABCD')).toBeNull();
    });

    it('affiche l\'erreur renvoyée par le serveur si le salon ne peut pas être rejoint', async () => {
        mocked.joinRoom.mockRejectedValue(new ApiError('Ce salon est fermé.', 410, 'room_closed'));
        render(<GuestView room={makeRoomState()} />);
        await advance(0);

        fireEvent.change(screen.getByLabelText('Votre pseudo'), { target: { value: 'Léa' } });
        fireEvent.click(screen.getByRole('button', { name: 'Rejoindre la soirée' }));
        await advance(0);

        expect(screen.getByRole('alert')).toHaveTextContent('Ce salon est fermé.');
    });
});

describe('GuestView — recherche avec debounce', () => {
    it('attend 400 ms sans frappe avant d\'appeler l\'API', async () => {
        await renderAsGuest();

        type('daft');
        await advance(SEARCH_DEBOUNCE_MS - 1);
        expect(mocked.search).not.toHaveBeenCalled();

        await advance(1);
        expect(mocked.search).toHaveBeenCalledTimes(1);
        expect(mocked.search).toHaveBeenCalledWith('ABCD', TOKEN, 'daft', expect.any(AbortSignal));
    });

    it('ne lance qu\'une seule recherche pour une saisie rapide', async () => {
        await renderAsGuest();

        // Chaque frappe arrive avant l'échéance de la précédente : le compte à rebours repart de zéro.
        for (const partial of ['daf', 'daft', 'daft ', 'daft p', 'daft pu', 'daft pun', 'daft punk']) {
            type(partial);
            await advance(150);
        }

        expect(mocked.search).not.toHaveBeenCalled();

        await advance(SEARCH_DEBOUNCE_MS);

        expect(mocked.search).toHaveBeenCalledTimes(1);
        expect(mocked.search.mock.calls[0]?.[2]).toBe('daft punk');
    });

    it('n\'appelle pas l\'API en dessous de 3 caractères', async () => {
        await renderAsGuest();

        type('da');
        await advance(SEARCH_DEBOUNCE_MS * 3);

        expect(mocked.search).not.toHaveBeenCalled();
        expect(screen.getByText('Tapez au moins 3 caractères.')).toBeInTheDocument();

        // Les espaces ne comptent pas : "  da  " reste trop court.
        type('  da  ');
        await advance(SEARCH_DEBOUNCE_MS * 3);

        expect(mocked.search).not.toHaveBeenCalled();
    });

    it('affiche les résultats avec le badge de leur source', async () => {
        await renderAsGuest();
        await searchFor('daft punk');

        const results = within(screen.getByRole('list', { name: 'Résultats de recherche' })).getAllByRole('listitem');

        expect(results).toHaveLength(2);
        expect(within(results[0]!).getByText('One More Time')).toBeInTheDocument();
        expect(within(results[0]!).getByText('Spotify')).toBeInTheDocument();
        expect(within(results[1]!).getByText('Around the World')).toBeInTheDocument();
        expect(within(results[1]!).getByText('YouTube')).toBeInTheDocument();
    });

    it('annule la recherche précédente quand la saisie change', async () => {
        await renderAsGuest();
        await searchFor('daft');
        const firstSignal = mocked.search.mock.calls[0]?.[3];

        await searchFor('daft punk');

        expect(firstSignal?.aborted).toBe(true);
        expect(mocked.search).toHaveBeenCalledTimes(2);
    });

    it('vide les résultats quand la recherche est effacée', async () => {
        await renderAsGuest();
        await searchFor('daft punk');
        expect(screen.getByText('One More Time')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Effacer la recherche' }));
        await advance(SEARCH_DEBOUNCE_MS);

        expect(screen.queryByText('One More Time')).not.toBeInTheDocument();
    });

    it('prévient quand une source est indisponible mais affiche les résultats de l\'autre', async () => {
        mocked.search.mockResolvedValue({ results: [oneMoreTime], sources: { spotify: 'ok', youtube: 'error' } });
        await renderAsGuest();
        await searchFor('daft punk');

        expect(screen.getByText(/YouTube momentanément indisponible/)).toBeInTheDocument();
        expect(screen.getByText('One More Time')).toBeInTheDocument();
    });

    it('indique l\'absence de résultat', async () => {
        mocked.search.mockResolvedValue({ results: [], sources: { spotify: 'ok', youtube: 'ok' } });
        await renderAsGuest();
        await searchFor('zzzzzz');

        expect(screen.getByText('Aucun résultat pour « zzzzzz ».')).toBeInTheDocument();
    });
});

describe('GuestView — états du bouton d\'ajout', () => {
    it('passe de "ajouter" à "chargement" puis "ajouté"', async () => {
        const queued = makeItem({ track: { ...oneMoreTime, id: 99 } });
        let resolveAdd: (state: RoomState) => void = () => undefined;
        mocked.addToQueue.mockImplementation(() => new Promise<RoomState>((resolve) => (resolveAdd = resolve)));
        await renderAsGuest();
        await searchFor('daft punk');

        fireEvent.click(screen.getByRole('button', { name: 'Ajouter One More Time à la file' }));

        // Chargement : bouton désactivé (pas de double ajout par double appui).
        expect(screen.getByRole('button', { name: 'Ajout de One More Time en cours' })).toBeDisabled();
        expect(mocked.addToQueue).toHaveBeenCalledExactlyOnceWith('ABCD', TOKEN, 'spotify', 'sp1');

        await act(async () => resolveAdd(makeRoomState({ version: 1, queue: [queued] })));

        expect(screen.getByRole('button', { name: 'One More Time est dans la file' })).toBeDisabled();
        // L'autre résultat reste ajoutable.
        expect(screen.getByRole('button', { name: 'Ajouter Around the World à la file' })).toBeEnabled();
        // La file en lecture seule reflète l'état renvoyé par le serveur.
        expect(within(screen.getByRole('region', { name: "File d'attente" })).getByText('One More Time')).toBeInTheDocument();
    });

    it('affiche "déjà en file" sur un 409', async () => {
        mocked.addToQueue.mockRejectedValue(new ApiError('Ce morceau est déjà dans la file.', 409, 'duplicate_track'));
        await renderAsGuest();
        await searchFor('daft punk');

        fireEvent.click(screen.getByRole('button', { name: 'Ajouter One More Time à la file' }));
        await advance(0);

        const button = screen.getByRole('button', { name: 'One More Time est déjà dans la file' });

        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Déjà en file');
        // Un doublon inattendu révèle une file locale en retard : la page se resynchronise.
        expect(mocked.fetchRoomState).toHaveBeenCalled();
    });

    it('affiche "réessayer" sur une erreur, et permet de relancer l\'ajout', async () => {
        mocked.addToQueue
            .mockRejectedValueOnce(new ApiError('Erreur 500', 500))
            .mockResolvedValueOnce(makeRoomState({ version: 1, queue: [makeItem({ track: { ...oneMoreTime, id: 99 } })] }));
        await renderAsGuest();
        await searchFor('daft punk');

        fireEvent.click(screen.getByRole('button', { name: 'Ajouter One More Time à la file' }));
        await advance(0);

        const retry = screen.getByRole('button', { name: "Échec de l'ajout de One More Time, réessayer" });
        expect(retry).toBeEnabled();

        fireEvent.click(retry);
        await advance(0);

        expect(mocked.addToQueue).toHaveBeenCalledTimes(2);
        expect(screen.getByRole('button', { name: 'One More Time est dans la file' })).toBeInTheDocument();
    });

    it('marque d\'emblée "ajouté" un résultat déjà présent dans la file du salon', async () => {
        // Ajouté par un autre invité avant même la recherche.
        const room = makeRoomState({ version: 3, queue: [makeItem({ track: { ...aroundTheWorld, id: 50 }, added_by: { type: 'guest', name: 'Noé' } })] });
        await renderAsGuest(room);
        await searchFor('daft punk');

        expect(screen.getByRole('button', { name: 'Around the World est dans la file' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Ajouter One More Time à la file' })).toBeEnabled();
        expect(mocked.addToQueue).not.toHaveBeenCalled();
    });

    it('redemande un pseudo si le serveur ne reconnaît plus le jeton au moment de l\'ajout', async () => {
        mocked.addToQueue.mockRejectedValue(new ApiError('Jeton invité manquant ou invalide.', 401, 'guest_unauthenticated'));
        await renderAsGuest();
        await searchFor('daft punk');

        fireEvent.click(screen.getByRole('button', { name: 'Ajouter One More Time à la file' }));
        await advance(0);

        expect(screen.getByLabelText('Votre pseudo')).toBeInTheDocument();
        expect(window.localStorage.getItem('jukebox:guest:ABCD')).toBeNull();
    });
});

describe('GuestView — file et lecture', () => {
    it('affiche le morceau en cours et la file avec l\'auteur de chaque ajout, sans aucun contrôle hôte', async () => {
        const current = makeItem({ status: 'playing', added_by: { type: 'host', name: 'Camille' } });
        const next = makeItem({ added_by: { type: 'guest', name: 'Noé' } });
        const room = makeRoomState({
            version: 4,
            playback: { status: 'paused', current, position_ms: 65_000, position_updated_at: Date.now(), playlist: null },
            queue: [next],
        });

        await renderAsGuest(room);

        const nowPlaying = screen.getByRole('region', { name: 'En cours de lecture' });
        expect(within(nowPlaying).getByText(current.track.title)).toBeInTheDocument();
        expect(within(nowPlaying).getByText('Camille')).toBeInTheDocument();
        expect(within(nowPlaying).getByText('1:05')).toBeInTheDocument();

        const queue = screen.getByRole('region', { name: "File d'attente" });
        expect(within(queue).getByText(next.track.title)).toBeInTheDocument();
        expect(within(queue).getByText('Noé')).toBeInTheDocument();

        // Lecture seule : ni lecture/pause, ni skip, ni retrait.
        expect(screen.queryByRole('button', { name: /pause|lire|passer|retirer|monter|descendre/i })).not.toBeInTheDocument();
        // La barre de progression n'est pas un curseur pour l'invité.
        expect(screen.queryByRole('slider')).not.toBeInTheDocument();
    });

    it('affiche un message quand le salon est fermé', async () => {
        await renderAsGuest(makeRoomState({ status: 'closed' }));

        expect(screen.getByText(/Ce salon est fermé/)).toBeInTheDocument();
        expect(screen.queryByLabelText('Rechercher un morceau')).not.toBeInTheDocument();
    });

    it('signale la musique de fond et l\'origine du morceau en cours', async () => {
        const playlist = { id: 1, provider: 'spotify' as const, name: 'Soirée 2000', image_url: null, shuffled: false };
        const current = makeItem({ status: 'playing', added_by: { type: 'playlist', name: 'Soirée 2000' } });

        await renderAsGuest(
            makeRoomState({ version: 2, playback: { status: 'playing', current, position_ms: 0, position_updated_at: Date.now(), playlist } }),
        );

        expect(screen.getByText(/Vos morceaux passent/)).toHaveTextContent('Musique de fond : Soirée 2000. Vos morceaux passent avant.');
        const nowPlaying = screen.getByRole('region', { name: 'En cours de lecture' });
        expect(within(nowPlaying).getByText(/playlist/)).toHaveTextContent('playlist Soirée 2000');
        expect(within(nowPlaying).queryByText(/ajouté par/)).not.toBeInTheDocument();
    });
});
