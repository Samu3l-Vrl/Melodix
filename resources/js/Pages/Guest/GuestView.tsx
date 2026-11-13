// resources/js/Pages/Guest/GuestView.tsx — télécommande mobile de l'invité : pseudo, recherche, ajout, file en lecture seule.
import ConnectionStatus from '@/Components/ConnectionStatus';
import ProgressBar from '@/Components/ProgressBar';
import TrackRow from '@/Components/TrackRow';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { usePlaybackPosition } from '@/hooks/usePlaybackPosition';
import { useRoomState } from '@/hooks/useRoomState';
import { api, ApiError, isAbortError } from '@/lib/api';
import { clearGuestSession, lastNickname, loadGuestSession, saveGuestSession, type GuestSession } from '@/lib/guestSession';
import type { ConnectionState, Provider, RoomState, SearchResult, SourceStatus } from '@/types';
import { Head } from '@inertiajs/react';
import { Check, CircleAlert, ListMusic, LoaderCircle, Plus, Search, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';

interface GuestViewProps {
    /** État initial du salon, fourni par RoomController::join. */
    room: RoomState;
}

/** Délai d'inactivité de frappe avant de lancer la recherche. */
export const SEARCH_DEBOUNCE_MS = 400;
/** En dessous, aucune recherche n'est envoyée (protège le quota YouTube). */
export const SEARCH_MIN_LENGTH = 3;

/**
 * Étapes de l'écran :
 *  - checking : un jeton est stocké, on vérifie qu'il est encore valable ;
 *  - nickname : l'invité doit choisir un pseudo ;
 *  - ready    : l'invité est identifié, la télécommande est utilisable.
 */
type Phase = 'checking' | 'nickname' | 'ready';

/** État du bouton d'ajout d'un résultat. */
type AddStatus = 'idle' | 'loading' | 'added' | 'duplicate' | 'error';

const SOURCE_LABELS: Record<Provider, string> = { spotify: 'Spotify', youtube: 'YouTube' };

/** Identifiant stable d'un morceau, toutes sources confondues. */
const trackKey = (track: { provider: Provider; provider_track_id: string }): string =>
    `${track.provider}:${track.provider_track_id}`;

export default function GuestView({ room }: GuestViewProps) {
    const code = room.code;
    const { state, connection, clockOffsetMs, applyServerState, resync } = useRoomState(code, room);
    const positionMs = usePlaybackPosition(state.playback, clockOffsetMs);

    // --- Identité de l'invité ---------------------------------------------------
    const [session, setSession] = useState<GuestSession | null>(null);
    const [phase, setPhase] = useState<Phase>('checking');
    const [nickname, setNickname] = useState('');
    const [joining, setJoining] = useState(false);
    const [joinError, setJoinError] = useState<string | null>(null);

    /** Le serveur ne reconnaît plus le jeton : on repart de la saisie du pseudo. */
    const dropSession = useCallback((): void => {
        clearGuestSession(code);
        setSession(null);
        setPhase('nickname');
    }, [code]);

    // Reprise automatique : si un jeton est stocké pour ce salon, on le fait valider par le serveur.
    useEffect(() => {
        const stored = loadGuestSession(code);

        if (stored === null) {
            setNickname(lastNickname());
            setPhase('nickname');

            return;
        }

        let cancelled = false;

        api.me(code, stored.token)
            .then(({ guest }) => {
                if (!cancelled) {
                    setSession({ token: stored.token, nickname: guest.nickname });
                    setPhase('ready');
                }
            })
            .catch((error: unknown) => {
                if (cancelled) {
                    return;
                }

                if (error instanceof ApiError && error.status === 401) {
                    setNickname(stored.nickname);
                    dropSession();
                } else {
                    // Réseau instable : on fait confiance au jeton stocké. S'il est invalide,
                    // le premier appel protégé répondra 401 et ramènera à la saisie du pseudo.
                    setSession(stored);
                    setPhase('ready');
                }
            });

        return () => {
            cancelled = true;
        };
    }, [code, dropSession]);

    const submitNickname = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();

        const trimmed = nickname.trim();

        if (trimmed === '' || joining) {
            return;
        }

        setJoining(true);
        setJoinError(null);

        try {
            const { guest, token } = await api.joinRoom(code, trimmed);
            const next: GuestSession = { token, nickname: guest.nickname };

            saveGuestSession(code, next);
            setSession(next);
            setPhase('ready');
        } catch (error) {
            setJoinError(error instanceof ApiError ? error.message : 'Impossible de rejoindre le salon.');
        } finally {
            setJoining(false);
        }
    };

    // --- Recherche ---------------------------------------------------------------
    const [query, setQuery] = useState('');
    const debouncedQuery = useDebouncedValue(query.trim(), SEARCH_DEBOUNCE_MS);
    const [results, setResults] = useState<SearchResult[]>([]);
    const [sources, setSources] = useState<Partial<Record<Provider, SourceStatus>>>({});
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState<string | null>(null);
    const [searchedQuery, setSearchedQuery] = useState<string | null>(null);

    const token = session?.token ?? null;

    useEffect(() => {
        if (token === null || debouncedQuery.length < SEARCH_MIN_LENGTH) {
            setResults([]);
            setSources({});
            setSearchError(null);
            setSearchedQuery(null);
            setSearching(false);

            return;
        }

        // Une frappe ultérieure annule la requête en cours : seule la réponse à la DERNIÈRE saisie
        // est affichée, même si une réponse plus ancienne arrive après.
        const controller = new AbortController();

        setSearching(true);
        setSearchError(null);

        api.search(code, token, debouncedQuery, controller.signal)
            .then((response) => {
                setResults(response.results);
                setSources(response.sources);
                setSearchedQuery(debouncedQuery);
                setSearching(false);
            })
            .catch((error: unknown) => {
                if (isAbortError(error)) {
                    return;
                }

                setSearching(false);

                if (error instanceof ApiError && error.status === 401) {
                    dropSession();
                } else {
                    setResults([]);
                    setSearchError('La recherche a échoué. Réessayez.');
                }
            });

        return () => controller.abort();
    }, [code, token, debouncedQuery, dropSession]);

    // --- Ajout à la file -----------------------------------------------------------
    const [addStatus, setAddStatus] = useState<Record<string, AddStatus>>({});

    /** Morceaux déjà présents dans le salon (en cours ou en attente), d'après l'état temps réel. */
    const queuedKeys = useMemo(() => {
        const keys = new Set(state.queue.map((item) => trackKey(item.track)));

        if (state.playback.current) {
            keys.add(trackKey(state.playback.current.track));
        }

        return keys;
    }, [state.queue, state.playback.current]);

    // Quand un morceau quitte le salon (joué, passé, retiré), son bouton redevient "Ajouter" :
    // rien n'interdit de le remettre en file plus tard dans la soirée.
    const previousQueuedKeysRef = useRef(queuedKeys);

    useEffect(() => {
        const departed = [...previousQueuedKeysRef.current].filter((key) => !queuedKeys.has(key));

        previousQueuedKeysRef.current = queuedKeys;

        if (departed.length > 0) {
            setAddStatus((previous) => {
                const next = { ...previous };

                for (const key of departed) {
                    delete next[key];
                }

                return next;
            });
        }
    }, [queuedKeys]);

    const addTrack = async (track: SearchResult): Promise<void> => {
        if (token === null) {
            return;
        }

        const key = trackKey(track);

        setAddStatus((previous) => ({ ...previous, [key]: 'loading' }));

        try {
            // La réponse contient l'état complet : la file se met à jour sans attendre l'event temps réel.
            applyServerState(await api.addToQueue(code, token, track.provider, track.provider_track_id));
            setAddStatus((previous) => ({ ...previous, [key]: 'added' }));
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                dropSession();

                return;
            }

            // 409 = quelqu'un l'a déjà ajouté : ce n'est pas une erreur, juste une information.
            const duplicate = error instanceof ApiError && error.status === 409;

            setAddStatus((previous) => ({ ...previous, [key]: duplicate ? 'duplicate' : 'error' }));

            if (duplicate) {
                // Un doublon inattendu signifie que notre file locale est en retard : on la recharge.
                void resync();
            }
        }
    };

    const failedSources = (Object.keys(sources) as Provider[]).filter((provider) => sources[provider] === 'error');
    const current = state.playback.current;

    // --- Rendu -----------------------------------------------------------------------
    if (state.status === 'closed') {
        return (
            <Shell code={code} connection={connection}>
                <p className="mt-16 text-center text-lg text-slate-300">Ce salon est fermé. Merci d'avoir participé !</p>
            </Shell>
        );
    }

    if (phase === 'checking') {
        return (
            <Shell code={code} connection={connection}>
                <p className="mt-16 flex items-center justify-center gap-2 text-slate-400">
                    <LoaderCircle aria-hidden="true" className="h-5 w-5 animate-spin" />
                    Connexion au salon…
                </p>
            </Shell>
        );
    }

    if (phase === 'nickname') {
        return (
            <Shell code={code} connection={connection}>
                <form onSubmit={(event) => void submitNickname(event)} className="mt-10 rounded-2xl bg-ink-900 p-5 ring-1 ring-white/10">
                    <h2 className="text-xl font-bold">Bienvenue !</h2>
                    <p className="mt-1 text-sm text-slate-400">Choisissez un pseudo : il sera affiché à côté des morceaux que vous ajoutez.</p>

                    <label htmlFor="nickname" className="mt-5 block text-sm font-medium text-slate-300">
                        Votre pseudo
                    </label>
                    <input
                        id="nickname"
                        value={nickname}
                        onChange={(event) => setNickname(event.target.value)}
                        maxLength={24}
                        autoComplete="nickname"
                        autoFocus
                        // 16px minimum : en dessous, iOS zoome sur le champ au focus.
                        className="mt-1.5 w-full rounded-lg border-0 bg-ink-800 px-4 py-3 text-base text-white ring-1 ring-white/10 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-accent"
                        placeholder="Ex. Léa"
                    />

                    {joinError && (
                        <p role="alert" className="mt-3 text-sm text-rose-300">
                            {joinError}
                        </p>
                    )}

                    <button
                        type="submit"
                        disabled={nickname.trim() === '' || joining}
                        className="mt-5 w-full rounded-lg bg-accent-strong px-5 py-3 font-semibold text-white transition hover:bg-accent disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {joining ? 'Connexion…' : 'Rejoindre la soirée'}
                    </button>
                </form>
            </Shell>
        );
    }

    return (
        <Shell code={code} connection={connection} nickname={session?.nickname}>
            {/* --- En cours de lecture ------------------------------------------------ */}
            <section aria-label="En cours de lecture" className="rounded-2xl bg-ink-900 p-4 ring-1 ring-white/10">
                {current ? (
                    <>
                        <TrackRow track={current.track} addedBy={current.added_by} />
                        <ProgressBar positionMs={positionMs} durationMs={current.track.duration_ms} className="mt-3" />
                    </>
                ) : (
                    <p className="py-2 text-center text-sm text-slate-400">Rien ne joue pour l'instant. Ajoutez le premier morceau !</p>
                )}
            </section>

            {/* --- Recherche ---------------------------------------------------------- */}
            <section aria-label="Recherche" className="mt-5">
                <div className="relative">
                    <Search aria-hidden="true" className="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500" />
                    <label htmlFor="search" className="sr-only">
                        Rechercher un morceau
                    </label>
                    <input
                        id="search"
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Titre, artiste…"
                        autoComplete="off"
                        enterKeyHint="search"
                        className="w-full rounded-xl border-0 bg-ink-800 py-3 pl-11 pr-11 text-base text-white ring-1 ring-white/10 placeholder:text-slate-500 focus:outline-none focus:ring-2 focus:ring-accent [&::-webkit-search-cancel-button]:hidden"
                    />
                    {query !== '' && (
                        <button
                            type="button"
                            onClick={() => setQuery('')}
                            aria-label="Effacer la recherche"
                            className="absolute right-2 top-1/2 -translate-y-1/2 rounded-full p-2 text-slate-400 hover:text-white"
                        >
                            <X aria-hidden="true" className="h-4 w-4" />
                        </button>
                    )}
                </div>

                {query.trim() !== '' && query.trim().length < SEARCH_MIN_LENGTH && (
                    <p className="mt-2 text-sm text-slate-500">Tapez au moins {SEARCH_MIN_LENGTH} caractères.</p>
                )}

                {searching && (
                    <p role="status" className="mt-3 flex items-center gap-2 text-sm text-slate-400">
                        <LoaderCircle aria-hidden="true" className="h-4 w-4 animate-spin" />
                        Recherche…
                    </p>
                )}

                {searchError && (
                    <p role="alert" className="mt-3 text-sm text-rose-300">
                        {searchError}
                    </p>
                )}

                {failedSources.length > 0 && (
                    <p className="mt-3 flex items-center gap-2 text-sm text-amber-200">
                        <CircleAlert aria-hidden="true" className="h-4 w-4 shrink-0" />
                        {failedSources.map((provider) => SOURCE_LABELS[provider]).join(' et ')} momentanément indisponible : résultats
                        partiels.
                    </p>
                )}

                {!searching && searchedQuery !== null && results.length === 0 && searchError === null && (
                    <p className="mt-3 text-sm text-slate-400">Aucun résultat pour « {searchedQuery} ».</p>
                )}

                {results.length > 0 && (
                    <ul aria-label="Résultats de recherche" className="mt-3 divide-y divide-white/5 rounded-2xl bg-ink-900 ring-1 ring-white/10">
                        {results.map((track) => {
                            const key = trackKey(track);
                            const status = resolveAddStatus(addStatus[key], queuedKeys.has(key));

                            return (
                                <li key={key} className="p-3">
                                    <TrackRow track={track} actions={<AddButton status={status} title={track.title} onAdd={() => void addTrack(track)} />} />
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            {/* --- File d'attente (lecture seule) --------------------------------------- */}
            <section aria-label="File d'attente" className="mt-6">
                <h2 className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-400">
                    <ListMusic aria-hidden="true" className="h-4 w-4" />
                    À suivre
                    <span className="tabular rounded-full bg-white/10 px-2 py-0.5 text-xs text-slate-200">{state.queue.length}</span>
                </h2>

                {state.queue.length === 0 ? (
                    <p className="mt-3 text-sm text-slate-500">La file est vide.</p>
                ) : (
                    <ol className="mt-3 divide-y divide-white/5 rounded-2xl bg-ink-900 ring-1 ring-white/10">
                        {state.queue.map((item, index) => (
                            <li key={item.id} className="p-3">
                                <TrackRow track={item.track} index={index + 1} addedBy={item.added_by} />
                            </li>
                        ))}
                    </ol>
                )}
            </section>
        </Shell>
    );
}

// ---------------------------------------------------------------------------------
// Sous-composants et fonctions propres à cette page
// ---------------------------------------------------------------------------------

/**
 * État affiché sur le bouton d'un résultat : combine ce que CET invité vient de faire (état local)
 * et ce que contient réellement la file (état temps réel, qui inclut les ajouts des autres invités).
 */
function resolveAddStatus(local: AddStatus | undefined, inQueue: boolean): AddStatus {
    // Une action en cours ou un message à lire priment sur le reste.
    if (local === 'loading' || local === 'error' || local === 'duplicate') {
        return local;
    }

    return inQueue ? 'added' : (local ?? 'idle');
}

interface ShellProps {
    code: string;
    connection: ConnectionState;
    nickname?: string;
    children: ReactNode;
}

/** Cadre commun aux différentes étapes : en-tête collant (code, pseudo, connexion) + contenu. */
function Shell({ code, connection, nickname, children }: ShellProps) {
    return (
        <>
            <Head title={`Salon ${code}`} />

            <div className="mx-auto min-h-full max-w-lg px-4 pb-safe">
                <header className="sticky top-0 z-10 -mx-4 flex items-center justify-between gap-3 bg-ink-950/90 px-4 py-3 backdrop-blur">
                    <div className="min-w-0">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Salon</p>
                        <p className="font-code text-xl font-bold tracking-[0.3em]">{code}</p>
                    </div>
                    <div className="flex min-w-0 items-center gap-3">
                        {nickname && <span className="truncate text-sm text-slate-300">{nickname}</span>}
                        <ConnectionStatus state={connection} compact />
                    </div>
                </header>

                <main className="pt-2">{children}</main>
            </div>
        </>
    );
}

interface AddButtonProps {
    status: AddStatus;
    title: string;
    onAdd: () => void;
}

/**
 * Bouton d'ajout à cinq états. Chaque état a une icône ET un libellé accessible distincts :
 * l'information ne repose jamais sur la seule couleur.
 */
function AddButton({ status, title, onAdd }: AddButtonProps) {
    // Zone tactile de 44 px minimum (recommandation mobile).
    const base = 'flex h-11 min-w-11 items-center justify-center gap-1 rounded-full px-3 text-sm font-semibold transition';

    switch (status) {
        case 'loading':
            return (
                <button type="button" disabled aria-label={`Ajout de ${title} en cours`} className={`${base} bg-white/10 text-slate-300`}>
                    <LoaderCircle aria-hidden="true" className="h-5 w-5 animate-spin" />
                </button>
            );

        case 'added':
            return (
                <button type="button" disabled aria-label={`${title} est dans la file`} className={`${base} bg-emerald-500/15 text-emerald-300`}>
                    <Check aria-hidden="true" className="h-5 w-5" />
                    <span>Ajouté</span>
                </button>
            );

        case 'duplicate':
            return (
                <button type="button" disabled aria-label={`${title} est déjà dans la file`} className={`${base} bg-amber-500/15 text-amber-200`}>
                    <span>Déjà en file</span>
                </button>
            );

        case 'error':
            // Reste cliquable : l'invité peut réessayer.
            return (
                <button type="button" onClick={onAdd} aria-label={`Échec de l'ajout de ${title}, réessayer`} className={`${base} bg-rose-500/15 text-rose-200`}>
                    <CircleAlert aria-hidden="true" className="h-5 w-5" />
                    <span>Réessayer</span>
                </button>
            );

        default:
            return (
                <button type="button" onClick={onAdd} aria-label={`Ajouter ${title} à la file`} className={`${base} bg-accent-strong text-white hover:bg-accent`}>
                    <Plus aria-hidden="true" className="h-5 w-5" />
                </button>
            );
    }
}
