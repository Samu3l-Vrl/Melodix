// resources/js/__tests__/PlaylistPicker.test.tsx — sélecteur de playlist de fond (accueil et écran hôte).
import PlaylistPicker, { type PlaylistChoice } from '@/Components/PlaylistPicker';
import { api, ApiError } from '@/lib/api';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/api', async (importOriginal) => {
    const original = await importOriginal<typeof import('@/lib/api')>();

    return { ...original, api: { spotifyPlaylists: vi.fn() } };
});

const spotifyPlaylists = vi.mocked(api.spotifyPlaylists);

/** Rend le composant avec un état réel, et expose la dernière valeur reçue. */
function renderPicker(spotifyEnabled = true, error: string | null = null): { last: () => PlaylistChoice } {
    let latest: PlaylistChoice = { url: '', shuffle: false };

    function Harness() {
        const [value, setValue] = useState<PlaylistChoice>(latest);

        return (
            <PlaylistPicker
                value={value}
                onChange={(next) => {
                    latest = next;
                    setValue(next);
                }}
                spotifyEnabled={spotifyEnabled}
                error={error}
            />
        );
    }

    render(<Harness />);

    return { last: () => latest };
}

beforeEach(() => {
    spotifyPlaylists.mockReset();
    spotifyPlaylists.mockResolvedValue({
        playlists: [
            { id: 'pl1', name: 'Apéro', image_url: null, track_count: 42, owner: 'Camille' },
            { id: 'pl2', name: 'Dancefloor', image_url: null, track_count: 120, owner: 'Camille' },
        ],
    });
});

describe('PlaylistPicker', () => {
    it('liste les playlists Spotify de l\'hôte et sélectionne celle choisie', async () => {
        const picker = renderPicker();

        const option = await screen.findByRole('button', { name: /Apéro/ });
        expect(screen.getByText('42 titres · Camille')).toBeInTheDocument();

        fireEvent.click(option);

        expect(picker.last().url).toBe('https://open.spotify.com/playlist/pl1');
        expect(screen.getByRole('option', { name: /Apéro/ })).toHaveAttribute('aria-selected', 'true');
        // Le lien apparaît dans le champ : l'hôte voit exactement ce qui sera importé.
        expect(screen.getByLabelText(/collez un lien de playlist/)).toHaveValue('https://open.spotify.com/playlist/pl1');
    });

    it('désélectionne au second clic', async () => {
        const picker = renderPicker();

        fireEvent.click(await screen.findByRole('button', { name: /Apéro/ }));
        fireEvent.click(screen.getByRole('button', { name: /Apéro/ }));

        expect(picker.last().url).toBe('');
    });

    it('accepte un lien collé, Spotify ou YouTube, et l\'option aléatoire', async () => {
        const picker = renderPicker();
        await screen.findByRole('button', { name: /Apéro/ });

        fireEvent.change(screen.getByLabelText(/collez un lien de playlist/), {
            target: { value: 'https://www.youtube.com/playlist?list=PLabc' },
        });
        fireEvent.click(screen.getByLabelText('Lecture aléatoire'));

        expect(picker.last()).toEqual({ url: 'https://www.youtube.com/playlist?list=PLabc', shuffle: true });
        // Aucune playlist Spotify ne correspond à ce lien.
        expect(screen.getByRole('option', { name: /Apéro/ })).toHaveAttribute('aria-selected', 'false');
    });

    it('ne charge pas les playlists Spotify quand l\'hôte n\'est pas Premium', () => {
        renderPicker(false);

        expect(spotifyPlaylists).not.toHaveBeenCalled();
        expect(screen.queryByText('Vos playlists Spotify')).not.toBeInTheDocument();
        expect(screen.getByLabelText(/Lien de playlist/)).toHaveAttribute('placeholder', 'https://www.youtube.com/playlist?list=…');
    });

    it('affiche l\'erreur du serveur sous le champ', () => {
        renderPicker(false, 'Playlist introuvable.');

        expect(screen.getByRole('alert')).toHaveTextContent('Playlist introuvable.');
        expect(screen.getByLabelText(/Lien de playlist/)).toHaveAttribute('aria-invalid', 'true');
    });

    it('reste utilisable par lien si la liste Spotify ne peut pas être chargée', async () => {
        spotifyPlaylists.mockRejectedValue(new ApiError('Impossible de lire vos playlists Spotify.', 502));
        renderPicker();

        await waitFor(() => expect(screen.getByText('Impossible de lire vos playlists Spotify.')).toBeInTheDocument());
        expect(screen.getByLabelText(/collez un lien de playlist/)).toBeEnabled();
    });
});
