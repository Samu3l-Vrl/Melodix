// resources/js/Components/PlaylistPicker.tsx — choix d'une playlist de fond : une de ses playlists Spotify, ou un lien collé.
// Utilisé à la création du salon (accueil) et sur l'écran hôte (changer de playlist).
import ProviderBadge from '@/Components/ProviderBadge';
import { api } from '@/lib/api';
import type { SpotifyPlaylistOption } from '@/types';
import { Check, ListMusic, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface PlaylistChoice {
    /** Lien de la playlist (vide = pas de playlist). */
    url: string;
    shuffle: boolean;
}

interface PlaylistPickerProps {
    value: PlaylistChoice;
    onChange: (value: PlaylistChoice) => void;
    /** Si faux, la liste des playlists Spotify n'est pas chargée (hôte non Premium). */
    spotifyEnabled: boolean;
    /** Message d'erreur renvoyé par le serveur (lien non reconnu, playlist introuvable…). */
    error?: string | null;
}

const spotifyUrl = (id: string): string => `https://open.spotify.com/playlist/${id}`;

export default function PlaylistPicker({ value, onChange, spotifyEnabled, error = null }: PlaylistPickerProps) {
    const [playlists, setPlaylists] = useState<SpotifyPlaylistOption[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    useEffect(() => {
        if (!spotifyEnabled) {
            setPlaylists([]);

            return;
        }

        let cancelled = false;

        api.spotifyPlaylists()
            .then(({ playlists: list }) => {
                if (!cancelled) {
                    setPlaylists(list);
                }
            })
            .catch((reason: unknown) => {
                if (!cancelled) {
                    setPlaylists([]);
                    setLoadError(reason instanceof Error ? reason.message : 'Impossible de charger vos playlists Spotify.');
                }
            });

        return () => {
            cancelled = true;
        };
    }, [spotifyEnabled]);

    const selectedSpotifyId = playlists?.find((playlist) => spotifyUrl(playlist.id) === value.url)?.id ?? null;

    return (
        <div className="space-y-3">
            {spotifyEnabled && (
                <div>
                    <p className="text-sm font-medium text-slate-300">Vos playlists Spotify</p>

                    {playlists === null && (
                        <p className="mt-2 flex items-center gap-2 text-sm text-slate-400">
                            <LoaderCircle aria-hidden="true" className="h-4 w-4 animate-spin" />
                            Chargement…
                        </p>
                    )}

                    {loadError && <p className="mt-2 text-sm text-amber-200">{loadError}</p>}

                    {playlists !== null && playlists.length === 0 && !loadError && (
                        <p className="mt-2 text-sm text-slate-500">Aucune playlist trouvée sur votre compte.</p>
                    )}

                    {playlists !== null && playlists.length > 0 && (
                        <ul role="listbox" aria-label="Vos playlists Spotify" className="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-lg bg-ink-800 p-1 ring-1 ring-white/10">
                            {playlists.map((playlist) => {
                                const selected = playlist.id === selectedSpotifyId;

                                return (
                                    <li key={playlist.id} role="option" aria-selected={selected}>
                                        <button
                                            type="button"
                                            // Un second clic désélectionne.
                                            onClick={() => onChange({ ...value, url: selected ? '' : spotifyUrl(playlist.id) })}
                                            className={`flex w-full items-center gap-3 rounded-md px-2 py-1.5 text-left transition ${selected ? 'bg-accent-strong/30 ring-1 ring-accent' : 'hover:bg-white/5'}`}
                                        >
                                            <div className="h-9 w-9 shrink-0 overflow-hidden rounded bg-ink-700">
                                                {playlist.image_url ? (
                                                    <img src={playlist.image_url} alt="" loading="lazy" className="h-full w-full object-cover" />
                                                ) : (
                                                    <ListMusic aria-hidden="true" className="m-2 h-5 w-5 text-slate-500" />
                                                )}
                                            </div>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-slate-100">{playlist.name}</span>
                                                <span className="block truncate text-xs text-slate-400">
                                                    {playlist.track_count !== null && `${playlist.track_count} titres`}
                                                    {playlist.owner && ` · ${playlist.owner}`}
                                                </span>
                                            </span>
                                            {selected && <Check aria-hidden="true" className="h-4 w-4 shrink-0 text-accent" />}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            )}

            <div>
                <label htmlFor="playlist-url" className="flex items-center gap-2 text-sm font-medium text-slate-300">
                    {spotifyEnabled ? 'Ou collez un lien de playlist' : 'Lien de playlist'}
                    <span className="flex gap-1">
                        {spotifyEnabled && <ProviderBadge provider="spotify" />}
                        <ProviderBadge provider="youtube" />
                    </span>
                </label>
                <input
                    id="playlist-url"
                    type="url"
                    inputMode="url"
                    value={value.url}
                    onChange={(event) => onChange({ ...value, url: event.target.value })}
                    placeholder={spotifyEnabled ? 'https://open.spotify.com/playlist/…' : 'https://www.youtube.com/playlist?list=…'}
                    aria-invalid={error !== null}
                    aria-describedby={error ? 'playlist-error' : undefined}
                    className="mt-1.5 w-full rounded-lg border-0 bg-ink-800 px-3 py-2.5 text-sm text-white ring-1 ring-white/10 placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-accent"
                />
                {error && (
                    <p id="playlist-error" role="alert" className="mt-2 text-sm text-rose-300">
                        {error}
                    </p>
                )}
            </div>

            <label className="flex items-center gap-2 text-sm text-slate-300">
                <input
                    type="checkbox"
                    checked={value.shuffle}
                    onChange={(event) => onChange({ ...value, shuffle: event.target.checked })}
                    className="h-4 w-4 rounded border-white/20 bg-ink-800 text-accent-strong focus:ring-accent"
                />
                Lecture aléatoire
            </label>
        </div>
    );
}
