// resources/js/Pages/Host/HostView.tsx — écran hôte (TV/PC) : lecteur, morceau en cours, file, QR code, contrôles.
// Principe : cette page ne décide de rien. Chaque action part au serveur ; c'est l'état renvoyé
// (ou diffusé) qui pilote le lecteur. L'écran hôte est "juste" le client qui possède les haut-parleurs.
import ConnectionStatus from '@/Components/ConnectionStatus';
import PlaylistPicker, { type PlaylistChoice } from '@/Components/PlaylistPicker';
import ProgressBar from '@/Components/ProgressBar';
import ProviderBadge from '@/Components/ProviderBadge';
import TrackRow from '@/Components/TrackRow';
import { usePlaybackPosition } from '@/hooks/usePlaybackPosition';
import { useRoomState } from '@/hooks/useRoomState';
import { api, ApiError } from '@/lib/api';
import { subscribeToHost } from '@/lib/echo';
import { computePositionMs } from '@/lib/time';
import { PlayerError } from '@/player/PlayerAdapter';
import { PlayerManager, type AdapterFactory } from '@/player/PlayerManager';
import { SpotifyAdapter } from '@/player/SpotifyAdapter';
import { YouTubeAdapter } from '@/player/YouTubeAdapter';
import type { HostUser, Provider, QueueItem, RoomState } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, CircleAlert, ListMusic, LoaderCircle, Music, Pause, Play, Power, Shuffle, SkipForward, Trash2, Users, X } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';

interface HostViewProps {
    /** État initial du salon (RoomStateResource). */
    room: RoomState;
    host: HostUser;
    /** URL encodée dans le QR code (basée sur JUKEBOX_PUBLIC_URL). */
    join_url: string;
}

/** Écart toléré entre la position réelle du lecteur et celle attendue par le serveur. */
const DRIFT_TOLERANCE_MS = 2500;
/** Intervalle minimal entre deux corrections de dérive envoyées au serveur. */
const DRIFT_REPORT_INTERVAL_MS = 3000;
/** Durée d'affichage d'un message temporaire. */
const NOTICE_DURATION_MS = 6000;
/** Durée d'affichage d'une erreur de lecture. */
const ERROR_NOTICE_DURATION_MS = 30000;

export default function HostView({ room, host, join_url: joinUrl }: HostViewProps) {
    const code = room.code;
    const { state, connection, clockOffsetMs, applyServerState, resync } = useRoomState(code, room);
    const positionMs = usePlaybackPosition(state.playback, clockOffsetMs);

    const [started, setStarted] = useState(false);
    const [notice, setNoticeState] = useState<{ text: string; error: boolean } | null>(null);
    const setNotice = useCallback((text: string | null, error = false): void => {
        setNoticeState(text === null ? null : { text, error });
    }, []);
    const [guestsCount, setGuestsCount] = useState<number | null>(null);

    const youtubeContainerRef = useRef<HTMLDivElement>(null);
    const managerRef = useRef<PlayerManager | null>(null);
    /** Id de l'élément de file actuellement chargé dans le lecteur. */
    const loadedKeyRef = useRef<number | null>(null);
    /** Version pour laquelle un démarrage automatique a déjà été demandé (évite les doublons). */
    const autoStartVersionRef = useRef<number | null>(null);
    const lastDriftReportRef = useRef(0);

    // Références toujours à jour pour les callbacks créés une seule fois (events du lecteur).
    const stateRef = useRef(state);
    const clockOffsetRef = useRef(clockOffsetMs);
    stateRef.current = state;
    clockOffsetRef.current = clockOffsetMs;

    const { playback, queue } = state;
    const current = playback.current;
    const currentProvider: Provider | null = current?.track.provider ?? null;

    // --- Messages temporaires ---------------------------------------------------------
    useEffect(() => {
        if (notice === null) {
            return;
        }

        // Une erreur de lecture reste affichée plus longtemps : c'est souvent la seule trace d'un morceau sauté.
        const timer = setTimeout(() => setNoticeState(null), notice.error ? ERROR_NOTICE_DURATION_MS : NOTICE_DURATION_MS);

        return () => clearTimeout(timer);
    }, [notice]);

    /**
     * Exécute une action serveur et applique l'état complet renvoyé.
     * Toutes les actions de la page passent par ici : un seul endroit gère les erreurs.
     */
    const run = useCallback(
        async (action: () => Promise<RoomState>, options: { silent?: boolean } = {}): Promise<void> => {
            try {
                applyServerState(await action());
            } catch (error) {
                if (!(error instanceof ApiError)) {
                    return;
                }

                // Limitation volontaire des mises à jour de position : rien à signaler.
                if (error.code === 'seek_throttled') {
                    return;
                }

                // Notre vue était périmée (un invité a ajouté un morceau, un autre onglet a agi…) : on se recale.
                if (error.code === 'queue_conflict') {
                    void resync();
                }

                if (!options.silent) {
                    setNotice(error.status === 401 ? 'Session expirée : reconnectez-vous depuis l\'accueil.' : error.message);
                }
            }
        },
        [applyServerState, resync],
    );

    // --- Canal privé de l'hôte : arrivées d'invités --------------------------------------
    useEffect(
        () =>
            subscribeToHost(code, {
                onGuestJoined: (event) => {
                    setGuestsCount(event.guests_count);
                    setNotice(`${event.guest.nickname} a rejoint la soirée`);
                },
            }),
        [code],
    );

    // --- Lecteur ---------------------------------------------------------------------

    /**
     * Le lecteur réel s'écarte de l'horloge du serveur (mise en mémoire tampon, publicité YouTube,
     * déplacement dans les commandes natives) : on envoie alors sa position réelle au serveur,
     * qui la rediffuse. Les barres de progression des invités restent ainsi justes.
     */
    const reportDrift = useCallback(
        (key: number, actualMs: number): void => {
            const currentPlayback = stateRef.current.playback;

            if (currentPlayback.status !== 'playing' || currentPlayback.current?.id !== key) {
                return;
            }

            const expectedMs = computePositionMs(currentPlayback, clockOffsetRef.current);
            const now = Date.now();

            if (Math.abs(expectedMs - actualMs) < DRIFT_TOLERANCE_MS || now - lastDriftReportRef.current < DRIFT_REPORT_INTERVAL_MS) {
                return;
            }

            lastDriftReportRef.current = now;
            void run(() => api.playback(code, { action: 'seek', position_ms: Math.round(actualMs) }), { silent: true });
        },
        [code, run],
    );

    /**
     * "Démarrer la session" : les navigateurs interdisent de lancer du son sans geste de l'utilisateur.
     * Ce clic est ce geste ; on en profite pour créer et initialiser les lecteurs.
     */
    const startSession = (): void => {
        if (managerRef.current !== null) {
            return;
        }

        const factories: Partial<Record<Provider, AdapterFactory>> = {
            youtube: () => {
                const container = youtubeContainerRef.current;

                if (container === null) {
                    throw new PlayerError('init', 'Conteneur du lecteur YouTube introuvable');
                }

                return new YouTubeAdapter(container);
            },
        };

        // Sans compte Premium, aucun adaptateur Spotify : un morceau Spotify serait signalé illisible et sauté.
        if (state.spotify_enabled) {
            factories.spotify = () =>
                new SpotifyAdapter({
                    getToken: async () => (await api.spotifyToken()).access_token,
                    deviceName: `Jukebox ${code}`,
                });
        }

        const manager = new PlayerManager(factories, {
            // Fin naturelle : le serveur marque le morceau "joué" et démarre le suivant.
            onTrackEnded: (key) => void run(() => api.playback(code, { action: 'ended', item_id: key })),
            // Morceau illisible (vidéo non intégrable, etc.) : on le saute sans intervention humaine.
            onTrackError: (key, error) => {
                // Trace complète dans la console du navigateur (F12) pour le diagnostic.
                console.warn('[Jukebox] Morceau sauté après une erreur de lecture', { key, code: error.code, message: error.message });
                setNotice(`Lecture impossible (${error.message}) — passage au morceau suivant.`, true);
                void run(() => api.skip(code, key), { silent: true });
            },
            onProgress: reportDrift,
        });

        managerRef.current = manager;
        void manager.unlock();
        setStarted(true);
    };

    // Destruction des lecteurs quand on quitte la page.
    useEffect(
        () => () => {
            managerRef.current?.destroy();
            managerRef.current = null;
        },
        [],
    );

    const currentId = current?.id ?? null;
    const status = playback.status;

    // Réconciliation : aligne le lecteur sur l'état du serveur à chaque changement de morceau ou de statut.
    useEffect(() => {
        const manager = managerRef.current;

        if (!started || manager === null) {
            return;
        }

        const latest = stateRef.current.playback;

        if (latest.current === null) {
            loadedKeyRef.current = null;
            void manager.stop();

            return;
        }

        if (loadedKeyRef.current !== latest.current.id) {
            // Nouveau morceau (ou premier chargement après "Démarrer", y compris après un rechargement
            // de la page en pleine lecture : on reprend à la position calculée, pas au début).
            loadedKeyRef.current = latest.current.id;
            void manager.load(latest.current.id, latest.current.track, {
                positionMs: computePositionMs(latest, clockOffsetRef.current),
                autoplay: latest.status === 'playing',
            });
        } else if (latest.status === 'playing') {
            void manager.play();
        } else {
            void manager.pause();
        }
    }, [started, currentId, status]);

    // Démarrage automatique : la session est lancée, rien ne joue, et il y a quelque chose à jouer
    // (un morceau en file, ou une playlist de fond). Une seule demande par version d'état.
    const hasPlaylist = playback.playlist !== null;

    useEffect(() => {
        if (!started || status !== 'idle' || currentId !== null || (queue.length === 0 && !hasPlaylist)) {
            return;
        }

        if (autoStartVersionRef.current === state.version) {
            return;
        }

        autoStartVersionRef.current = state.version;
        void run(() => api.playback(code, { action: 'play' }));
    }, [started, status, currentId, queue.length, hasPlaylist, state.version, code, run]);

    // --- Actions de l'hôte --------------------------------------------------------------
    const togglePlay = (): void => {
        if (status === 'playing') {
            // On envoie la position réellement affichée : la pause fige exactement ce que l'hôte voit.
            void run(() => api.playback(code, { action: 'pause', position_ms: Math.round(positionMs) }));
        } else {
            void run(() => api.playback(code, { action: 'play' }));
        }
    };

    const skip = (): void => {
        void run(() => api.skip(code, currentId));
    };

    const seek = (targetMs: number): void => {
        // Le lecteur bouge tout de suite (réactivité), le serveur est informé dans la foulée.
        void managerRef.current?.seek(targetMs);
        void run(() => api.playback(code, { action: 'seek', position_ms: targetMs }));
    };

    const remove = (item: QueueItem): void => {
        void run(() => api.removeFromQueue(code, item.id));
    };

    /** Déplace un élément d'un cran (-1 = monter, +1 = descendre) et envoie le nouvel ordre complet. */
    const move = (index: number, direction: -1 | 1): void => {
        const target = index + direction;
        const order = queue.map((item) => item.id);
        const moved = order[index];
        const swapped = order[target];

        if (moved === undefined || swapped === undefined) {
            return;
        }

        order[index] = swapped;
        order[target] = moved;
        void run(() => api.reorderQueue(code, order));
    };

    const closeRoom = (): void => {
        if (window.confirm('Fermer le salon ? Les invités ne pourront plus ajouter de morceaux.')) {
            router.post(`/host/rooms/${code}/close`);
        }
    };

    const canPlay = current !== null || queue.length > 0 || hasPlaylist;

    // --- Playlist de fond -----------------------------------------------------------------
    const [playlistDialogOpen, setPlaylistDialogOpen] = useState(false);
    const [playlistChoice, setPlaylistChoice] = useState<PlaylistChoice>({ url: '', shuffle: false });
    const [playlistError, setPlaylistError] = useState<string | null>(null);
    const [playlistSaving, setPlaylistSaving] = useState(false);

    const openPlaylistDialog = (): void => {
        setPlaylistChoice({ url: '', shuffle: playback.playlist?.shuffled ?? false });
        setPlaylistError(null);
        setPlaylistDialogOpen(true);
    };

    const savePlaylist = async (): Promise<void> => {
        setPlaylistSaving(true);
        setPlaylistError(null);

        try {
            applyServerState(await api.setPlaylist(code, playlistChoice.url.trim(), playlistChoice.shuffle));
            setPlaylistDialogOpen(false);
        } catch (error) {
            // Message du serveur affiché tel quel (lien non reconnu, playlist éditoriale, quota…).
            setPlaylistError(error instanceof ApiError ? error.message : 'Impossible d\'importer cette playlist.');
        } finally {
            setPlaylistSaving(false);
        }
    };

    const removePlaylist = (): void => {
        if (window.confirm('Retirer la musique de fond ? La musique s\'arrêtera quand la file sera vide.')) {
            void run(() => api.removePlaylist(code));
        }
    };

    // Le QR code pointe vers une adresse que seul ce PC peut ouvrir : les téléphones afficheront
    // « site inaccessible ». Cas typique : JUKEBOX_PUBLIC_URL non renseigné.
    const joinHost = ((): string => {
        try {
            return new URL(joinUrl).hostname;
        } catch {
            return '';
        }
    })();
    const qrIsLocalOnly = ['127.0.0.1', 'localhost', '::1', '[::1]', '0.0.0.0'].includes(joinHost);

    return (
        <>
            <Head title={`Salon ${code}`} />

            <div className="flex h-screen min-h-[36rem] flex-col overflow-hidden">
                {/* --- Bandeau supérieur ------------------------------------------------------ */}
                <header className="flex shrink-0 items-center justify-between gap-6 border-b border-white/5 px-8 py-4">
                    <div className="flex items-center gap-3">
                        <Music aria-hidden="true" className="h-7 w-7 text-accent" />
                        <span className="text-xl font-bold">Jukebox</span>
                    </div>

                    <div className="flex items-center gap-6 text-sm text-slate-300">
                        {guestsCount !== null && (
                            <span className="flex items-center gap-1.5">
                                <Users aria-hidden="true" className="h-4 w-4" />
                                {guestsCount} invité{guestsCount > 1 ? 's' : ''}
                            </span>
                        )}
                        <ConnectionStatus state={connection} />
                        <span className="hidden text-slate-400 lg:inline">{host.name}</span>
                        <button
                            type="button"
                            onClick={closeRoom}
                            className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-slate-400 ring-1 ring-white/10 transition hover:text-rose-300 hover:ring-rose-400/50"
                        >
                            <Power aria-hidden="true" className="h-4 w-4" />
                            Fermer le salon
                        </button>
                    </div>
                </header>

                {/* --- Grille principale --------------------------------------------------------
                    16:9      : 2 colonnes — lecteur à gauche (2 rangées), file en haut à droite, QR en bas à droite.
                    Ultrawide : 3 colonnes — lecteur, file, QR côte à côte (on exploite la largeur au lieu d'étirer). */}
                <main className="grid min-h-0 flex-1 grid-cols-1 gap-6 overflow-y-auto p-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:grid-rows-[minmax(0,1fr)_auto] lg:overflow-hidden lg:p-8 ultrawide:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_minmax(18rem,0.55fr)] ultrawide:grid-rows-1">
                    {/* --- Lecteur + morceau en cours ------------------------------------------- */}
                    <section aria-label="En cours de lecture" className="flex min-h-0 flex-col gap-5 lg:row-span-2 ultrawide:row-span-1">
                        <div className="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-3xl bg-ink-900 ring-1 ring-white/10">
                            {/* Conteneur du lecteur YouTube : toujours présent dans le DOM (le lecteur y est créé une fois),
                                visible uniquement quand le morceau courant vient de YouTube. Taille minimale 200x200
                                exigée par YouTube. */}
                            <div
                                ref={youtubeContainerRef}
                                data-testid="youtube-player"
                                className={`h-full min-h-[200px] w-full min-w-[200px] ${currentProvider === 'youtube' ? '' : 'hidden'}`}
                            />

                            {currentProvider !== 'youtube' &&
                                (current?.track.album_art_url ? (
                                    <img src={current.track.album_art_url} alt="" className="h-full max-h-full w-auto max-w-full object-contain" />
                                ) : (
                                    <div className="flex flex-col items-center gap-4 p-8 text-center text-slate-500">
                                        <Music aria-hidden="true" className="h-24 w-24" />
                                        {current === null && <p className="text-2xl">Scannez le QR code pour ajouter le premier morceau</p>}
                                    </div>
                                ))}
                        </div>

                        <div className="shrink-0">
                            {current ? (
                                <>
                                    <div className="flex items-start justify-between gap-6">
                                        <div className="min-w-0">
                                            <h1 className="truncate text-4xl font-extrabold tracking-tight xl:text-5xl">{current.track.title}</h1>
                                            <p className="mt-2 truncate text-2xl text-slate-300">{current.track.artist}</p>
                                        </div>
                                        <ProviderBadge provider={current.track.provider} className="mt-2" />
                                    </div>
                                    <p className="mt-2 text-base text-slate-400">
                                        Ajouté par <span className="font-semibold text-slate-200">{current.added_by.name}</span>
                                    </p>
                                    <ProgressBar positionMs={positionMs} durationMs={current.track.duration_ms} onSeek={seek} className="mt-4" />
                                </>
                            ) : (
                                <h1 className="text-4xl font-extrabold tracking-tight text-slate-400">En attente de musique…</h1>
                            )}

                            <div className="mt-4 flex items-center gap-3">
                                <button
                                    type="button"
                                    onClick={togglePlay}
                                    disabled={!started || !canPlay}
                                    aria-label={status === 'playing' ? 'Mettre en pause' : 'Lire'}
                                    className="flex h-14 w-14 items-center justify-center rounded-full bg-accent-strong text-white transition hover:bg-accent disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {status === 'playing' ? (
                                        <Pause aria-hidden="true" className="h-7 w-7" />
                                    ) : (
                                        <Play aria-hidden="true" className="h-7 w-7 translate-x-0.5" />
                                    )}
                                </button>
                                <button
                                    type="button"
                                    onClick={skip}
                                    disabled={!started || current === null}
                                    aria-label="Passer au morceau suivant"
                                    className="flex h-14 w-14 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <SkipForward aria-hidden="true" className="h-6 w-6" />
                                </button>

                                {/* aria-live : les messages (arrivée d'un invité, morceau sauté) sont annoncés. */}
                                <p
                                    aria-live="polite"
                                    // Une erreur peut être longue : elle passe à la ligne au lieu d'être tronquée.
                                    className={`ml-2 min-w-0 flex-1 text-base ${notice?.error ? 'text-rose-300' : 'truncate text-amber-200'}`}
                                >
                                    {notice && (
                                        <span className="inline-flex items-start gap-2">
                                            <CircleAlert aria-hidden="true" className="mt-1 h-4 w-4 shrink-0" />
                                            <span>{notice.text}</span>
                                            {notice.error && (
                                                <button type="button" onClick={() => setNotice(null)} aria-label="Fermer le message" className="ml-1 text-slate-400 hover:text-white">
                                                    <X aria-hidden="true" className="mt-0.5 h-4 w-4" />
                                                </button>
                                            )}
                                        </span>
                                    )}
                                </p>
                            </div>

                            {!state.spotify_enabled && (
                                <p className="mt-3 text-sm text-slate-500">
                                    Lecture Spotify désactivée (compte non Premium) : seuls les morceaux YouTube sont proposés aux invités.
                                </p>
                            )}
                        </div>
                    </section>

                    {/* --- File d'attente ----------------------------------------------------- */}
                    <section aria-label="File d'attente" className="flex min-h-0 flex-col rounded-3xl bg-ink-900 ring-1 ring-white/10">
                        <h2 className="flex shrink-0 items-center justify-between px-6 pb-3 pt-5 text-lg font-bold">
                            À suivre
                            <span className="tabular rounded-full bg-white/10 px-3 py-0.5 text-sm font-semibold text-slate-200">{queue.length}</span>
                        </h2>

                        {/* Musique de fond : jouée quand la file des invités est vide. */}
                        <div className="mx-3 mb-2 flex shrink-0 items-center gap-3 rounded-2xl bg-ink-800 px-3 py-2.5 ring-1 ring-white/5">
                            {playback.playlist ? (
                                <>
                                    <div className="h-10 w-10 shrink-0 overflow-hidden rounded-md bg-ink-700">
                                        {playback.playlist.image_url ? (
                                            <img src={playback.playlist.image_url} alt="" className="h-full w-full object-cover" />
                                        ) : (
                                            <ListMusic aria-hidden="true" className="m-2.5 h-5 w-5 text-slate-500" />
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-xs uppercase tracking-wide text-slate-500">Musique de fond</p>
                                        <p className="flex items-center gap-1.5 truncate text-sm font-semibold text-slate-100">
                                            {playback.playlist.shuffled && <Shuffle aria-label="lecture aléatoire" className="h-3.5 w-3.5 shrink-0 text-accent" />}
                                            <span className="truncate">{playback.playlist.name}</span>
                                        </p>
                                    </div>
                                    <ProviderBadge provider={playback.playlist.provider} />
                                    <button type="button" onClick={openPlaylistDialog} className="rounded-lg px-2.5 py-1.5 text-sm text-slate-300 ring-1 ring-white/10 hover:text-white">
                                        Changer
                                    </button>
                                    <IconButton label="Retirer la musique de fond" danger onClick={removePlaylist}>
                                        <X aria-hidden="true" className="h-5 w-5" />
                                    </IconButton>
                                </>
                            ) : (
                                <>
                                    <p className="min-w-0 flex-1 text-sm text-slate-400">Pas de musique de fond : la lecture s'arrête quand la file est vide.</p>
                                    <button type="button" onClick={openPlaylistDialog} className="flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-slate-200 ring-1 ring-white/10 hover:ring-accent">
                                        <ListMusic aria-hidden="true" className="h-4 w-4" />
                                        Choisir une playlist
                                    </button>
                                </>
                            )}
                        </div>

                        {queue.length === 0 ? (
                            <p className="px-6 pb-6 text-slate-500">
                                {playback.playlist
                                    ? 'La file est vide : la playlist prend le relais. Les morceaux ajoutés par les invités passeront en priorité.'
                                    : 'La file est vide. Les morceaux ajoutés par les invités apparaîtront ici.'}
                            </p>
                        ) : (
                            <ol className="min-h-0 flex-1 divide-y divide-white/5 overflow-y-auto px-3 pb-3">
                                {queue.map((item, index) => (
                                    <li key={item.id} className="px-3 py-3">
                                        <TrackRow
                                            track={item.track}
                                            index={index + 1}
                                            addedBy={item.added_by}
                                            size="lg"
                                            actions={
                                                <>
                                                    <IconButton label={`Monter ${item.track.title}`} disabled={index === 0} onClick={() => move(index, -1)}>
                                                        <ArrowUp aria-hidden="true" className="h-5 w-5" />
                                                    </IconButton>
                                                    <IconButton
                                                        label={`Descendre ${item.track.title}`}
                                                        disabled={index === queue.length - 1}
                                                        onClick={() => move(index, 1)}
                                                    >
                                                        <ArrowDown aria-hidden="true" className="h-5 w-5" />
                                                    </IconButton>
                                                    <IconButton label={`Retirer ${item.track.title}`} danger onClick={() => remove(item)}>
                                                        <Trash2 aria-hidden="true" className="h-5 w-5" />
                                                    </IconButton>
                                                </>
                                            }
                                        />
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>

                    {/* --- Invitation : QR code + code, affichés en permanence ------------------ */}
                    <aside
                        aria-label="Rejoindre le salon"
                        className="flex items-center gap-6 rounded-3xl bg-ink-900 p-6 ring-1 ring-white/10 ultrawide:flex-col ultrawide:justify-center ultrawide:text-center"
                    >
                        {/* Fond blanc + marge : indispensables pour que les appareils photo lisent le QR code. */}
                        <div className="shrink-0 rounded-2xl bg-white p-3">
                            <QRCodeSVG value={joinUrl} size={168} level="M" title={`QR code pour rejoindre le salon ${code}`} />
                        </div>
                        <div className="min-w-0">
                            <p className="text-lg text-slate-300">Scannez pour ajouter un morceau</p>
                            <p className="mt-1 text-sm text-slate-500">ou saisissez ce code sur la page d'accueil</p>
                            <p data-testid="room-code" className="mt-2 font-code text-6xl font-black tracking-[0.25em] text-white">
                                {code}
                            </p>
                            <p className="mt-2 truncate text-sm text-slate-500">{joinUrl}</p>
                            {qrIsLocalOnly && (
                                <p role="alert" className="mt-2 flex items-start gap-1.5 text-sm text-amber-200">
                                    <CircleAlert aria-hidden="true" className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>
                                        Cette adresse ne fonctionne que sur ce PC : les téléphones ne pourront pas l'ouvrir. Renseignez
                                        JUKEBOX_PUBLIC_URL dans le fichier .env (voir le README, section 8).
                                    </span>
                                </p>
                            )}
                        </div>
                    </aside>
                </main>
            </div>

            {/* --- Choix de la musique de fond ------------------------------------------------------ */}
            {playlistDialogOpen && (
                <div role="dialog" aria-modal="true" aria-labelledby="playlist-title" className="fixed inset-0 z-40 flex items-center justify-center bg-ink-950/80 p-6">
                    <div className="w-full max-w-lg rounded-3xl bg-ink-900 p-6 ring-1 ring-white/10">
                        <h2 id="playlist-title" className="text-xl font-bold">
                            Musique de fond
                        </h2>
                        <p className="mt-1 text-sm text-slate-400">
                            Elle joue quand la file est vide et reprend du début une fois finie. Les morceaux des invités passent toujours en priorité.
                        </p>

                        <div className="mt-4">
                            <PlaylistPicker value={playlistChoice} onChange={setPlaylistChoice} spotifyEnabled={state.spotify_enabled} error={playlistError} />
                        </div>

                        <div className="mt-6 flex justify-end gap-3">
                            <button type="button" onClick={() => setPlaylistDialogOpen(false)} className="rounded-lg px-4 py-2 text-slate-300 hover:text-white">
                                Annuler
                            </button>
                            <button
                                type="button"
                                onClick={() => void savePlaylist()}
                                disabled={playlistSaving || playlistChoice.url.trim() === ''}
                                className="flex items-center gap-2 rounded-lg bg-accent-strong px-4 py-2 font-semibold text-white hover:bg-accent disabled:opacity-40"
                            >
                                {playlistSaving && <LoaderCircle aria-hidden="true" className="h-4 w-4 animate-spin" />}
                                {playlistSaving ? 'Import…' : 'Utiliser cette playlist'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* --- Voile de démarrage : débloque l'audio du navigateur ---------------------------- */}
            {!started && (
                <div role="dialog" aria-modal="true" aria-labelledby="start-title" className="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/95 p-6">
                    <div className="max-w-xl text-center">
                        <p className="font-code text-7xl font-black tracking-[0.25em]">{code}</p>
                        <h2 id="start-title" className="mt-6 text-3xl font-bold">
                            Votre salon est prêt
                        </h2>
                        <p className="mt-3 text-lg text-slate-400">
                            Les navigateurs bloquent le son tant que vous n'avez pas cliqué. Lancez la session, puis laissez cet écran ouvert :
                            c'est lui qui joue la musique.
                        </p>
                        <button
                            type="button"
                            onClick={startSession}
                            autoFocus
                            className="mt-8 inline-flex items-center gap-3 rounded-full bg-accent-strong px-10 py-5 text-2xl font-bold text-white transition hover:bg-accent"
                        >
                            <Play aria-hidden="true" className="h-7 w-7" />
                            Démarrer la session
                        </button>
                        <p className="mt-6">
                            <Link href="/" className="text-sm text-slate-500 underline-offset-4 hover:text-slate-300 hover:underline">
                                Retour à l'accueil
                            </Link>
                        </p>
                    </div>
                </div>
            )}
        </>
    );
}

// ---------------------------------------------------------------------------------
// Sous-composant propre à cette page
// ---------------------------------------------------------------------------------

interface IconButtonProps {
    /** Libellé accessible (le bouton n'affiche qu'une icône). */
    label: string;
    onClick: () => void;
    disabled?: boolean;
    danger?: boolean;
    children: ReactNode;
}

function IconButton({ label, onClick, disabled = false, danger = false, children }: IconButtonProps) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            className={`flex h-10 w-10 items-center justify-center rounded-full text-slate-400 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-25 ${danger ? 'hover:text-rose-300' : 'hover:text-white'}`}
        >
            {children}
        </button>
    );
}
