// resources/js/Pages/Home.tsx — page d'accueil : rejoindre un salon avec un code, ou se connecter avec Spotify pour en créer un.
import type { SharedProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { LogOut, Music2, Plus, Tv } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface HomeProps {
    /** Salons encore ouverts de l'hôte connecté (vide pour un visiteur). */
    rooms: { code: string; created_at: string | null }[];
}

/** Même alphabet que RoomCodeGenerator côté PHP : 4 lettres, sans I ni O. */
const CODE_PATTERN = /^[A-HJ-NP-Z]{4}$/;

export default function Home({ rooms }: HomeProps) {
    const { auth, flash, app } = usePage<SharedProps>().props;
    const [code, setCode] = useState('');
    const [creating, setCreating] = useState(false);

    const normalizedCode = code.trim().toUpperCase();
    const codeIsValid = CODE_PATTERN.test(normalizedCode);

    const join = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (codeIsValid) {
            router.visit(`/room/${normalizedCode}/join`);
        }
    };

    const createRoom = (): void => {
        // router.post gère le jeton CSRF et suit la redirection vers l'écran hôte.
        router.post('/rooms', {}, { onStart: () => setCreating(true), onFinish: () => setCreating(false) });
    };

    return (
        <>
            <Head title="Accueil" />

            <main className="mx-auto flex min-h-full max-w-md flex-col justify-center gap-8 px-5 py-10">
                <header className="text-center">
                    <Music2 aria-hidden="true" className="mx-auto h-12 w-12 text-accent" />
                    <h1 className="mt-3 text-4xl font-extrabold tracking-tight">{app.name}</h1>
                    <p className="mt-2 text-slate-400">Le jukebox collaboratif de vos soirées.</p>
                </header>

                {flash.error && (
                    <p role="alert" className="rounded-lg bg-rose-500/15 px-4 py-3 text-sm text-rose-200 ring-1 ring-rose-500/30">
                        {flash.error}
                    </p>
                )}

                {/* --- Invité : rejoindre avec un code --------------------------------- */}
                <section className="rounded-2xl bg-ink-900 p-5 ring-1 ring-white/10">
                    <h2 className="text-lg font-semibold">Rejoindre un salon</h2>
                    <p className="mt-1 text-sm text-slate-400">Scannez le QR code affiché sur l'écran, ou tapez le code à 4 lettres.</p>

                    <form onSubmit={join} className="mt-4 flex gap-2">
                        <label htmlFor="room-code" className="sr-only">
                            Code du salon
                        </label>
                        <input
                            id="room-code"
                            value={code}
                            onChange={(event) => setCode(event.target.value.toUpperCase().slice(0, 4))}
                            placeholder="ABCD"
                            maxLength={4}
                            autoCapitalize="characters"
                            autoComplete="off"
                            autoCorrect="off"
                            spellCheck={false}
                            className="w-full rounded-lg border-0 bg-ink-800 px-4 py-3 text-center font-code text-2xl font-bold tracking-[0.4em] text-white ring-1 ring-white/10 placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-accent"
                        />
                        <button
                            type="submit"
                            disabled={!codeIsValid}
                            className="rounded-lg bg-accent-strong px-5 font-semibold text-white transition hover:bg-accent disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Rejoindre
                        </button>
                    </form>
                </section>

                {/* --- Hôte : connexion Spotify puis création --------------------------- */}
                <section className="rounded-2xl bg-ink-900 p-5 ring-1 ring-white/10">
                    <h2 className="text-lg font-semibold">Organiser une soirée</h2>

                    {auth.user === null ? (
                        <>
                            <p className="mt-1 text-sm text-slate-400">
                                Connectez-vous avec Spotify pour créer un salon et l'afficher sur votre TV ou votre PC.
                            </p>
                            {/* Lien classique (pas un <Link> Inertia) : l'OAuth redirige vers un autre domaine. */}
                            <a
                                href="/auth/spotify/redirect"
                                className="mt-4 flex items-center justify-center gap-2 rounded-lg bg-spotify px-5 py-3 font-semibold text-black transition hover:brightness-110"
                            >
                                Se connecter avec Spotify
                            </a>
                        </>
                    ) : (
                        <>
                            <p className="mt-1 text-sm text-slate-400">
                                Connecté en tant que <span className="font-medium text-slate-200">{auth.user.name}</span>.
                            </p>

                            {!auth.user.spotify_premium && (
                                <p className="mt-3 rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-200 ring-1 ring-amber-500/30">
                                    Votre compte Spotify n'est pas Premium : la lecture Spotify sera désactivée. Vos invités pourront
                                    ajouter des morceaux YouTube.
                                </p>
                            )}

                            <button
                                type="button"
                                onClick={createRoom}
                                disabled={creating}
                                className="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-accent-strong px-5 py-3 font-semibold text-white transition hover:bg-accent disabled:opacity-50"
                            >
                                <Plus aria-hidden="true" className="h-5 w-5" />
                                {creating ? 'Création…' : 'Créer un salon'}
                            </button>

                            {rooms.length > 0 && (
                                <ul className="mt-4 space-y-2">
                                    {rooms.map((room) => (
                                        <li key={room.code}>
                                            <Link
                                                href={`/room/${room.code}/host`}
                                                className="flex items-center justify-between rounded-lg bg-ink-800 px-4 py-3 ring-1 ring-white/10 transition hover:ring-accent"
                                            >
                                                <span className="flex items-center gap-2 text-sm text-slate-300">
                                                    <Tv aria-hidden="true" className="h-4 w-4" />
                                                    Reprendre le salon
                                                </span>
                                                <span className="font-code text-lg font-bold tracking-[0.3em]">{room.code}</span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="mt-4 flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-200"
                            >
                                <LogOut aria-hidden="true" className="h-4 w-4" />
                                Se déconnecter
                            </Link>
                        </>
                    )}
                </section>
            </main>
        </>
    );
}
