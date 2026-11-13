# A — Architecture et données

## A.1 Arborescence

```
app/
├── Casts/                 Conversions d'attributs Eloquent (date à la milliseconde)
├── Console/Commands/      Commandes artisan (rooms:close-inactive)
├── Data/                  Objets de transfert immuables entre les services (TrackData…)
├── Enums/                 Valeurs fermées du domaine (Provider, statuts)
├── Events/                Events diffusés en temps réel (QueueUpdated, PlaybackStateChanged, GuestJoined)
├── Exceptions/            Erreurs métier, chacune avec son statut HTTP et son code machine
├── Http/
│   ├── Controllers/       Pages Inertia (racine), Api/ (invités, sans état), Host/ (hôte, session), Auth/ (OAuth)
│   ├── Middleware/        Inertia, résolution de l'invité par jeton, salon ouvert
│   ├── Requests/          Form Requests : validation + autorisation de chaque action
│   └── Resources/         API Resources : la forme exacte du JSON, miroir des types TypeScript
├── Models/                User (hôte), Room, Guest, Track, QueueItem
├── Policies/              RoomPolicy : seul le propriétaire pilote son salon
├── Providers/             Câblage (registre des sources, liaison {room}, Socialite Spotify)
└── Services/              La logique métier
    ├── Music/             MusicProvider + Spotify/YouTube, recherche unifiée, quota, résolution de morceau
    ├── Spotify/           Jeton d'accès de l'hôte (rafraîchissement)
    ├── QueueService       Mutations de la file, sous verrou
    ├── PlaybackService    Mutations de l'état de lecture
    ├── RoomService        Création / fermeture des salons
    ├── RoomCodeGenerator  Codes à 4 lettres
    └── RoomBroadcaster    Diffusion « au mieux » des events

resources/js/
├── app.tsx                Point d'entrée Inertia
├── types/                 Types partagés (alignés sur les Resources) + déclarations des SDK
├── lib/                   Modules sans React : client HTTP, Echo, réducteur de versions, temps, stockage invité
├── hooks/                 useRoomState, usePlaybackPosition, useDebouncedValue
├── player/                PlayerAdapter, SpotifyAdapter, YouTubeAdapter, PlayerManager
├── Components/            TrackRow, ProviderBadge, ConnectionStatus, ProgressBar
├── Pages/                 Home, Host/HostView, Guest/GuestView
└── __tests__/             Tests Vitest

routes/
├── web.php                Pages + actions de l'hôte (session, CSRF)
├── api.php                Endpoints sans état des invités + resynchronisation
├── channels.php           Autorisation du canal privé de l'hôte
└── console.php            Planification de rooms:close-inactive

tests/
├── Feature/               Tests Pest avec base de données et requêtes HTTP
├── Unit/                  Tests Pest purs (format des codes, durées ISO 8601)
├── Pest.php               Configuration + fonctions utilitaires
└── TestCase.php           Garde-fous communs (aucun appel HTTP réel)
```

Principe d'organisation : les **contrôleurs sont minces** (ils valident, appellent un service, sérialisent), la **logique vit dans `app/Services`**, et **la forme du JSON vit dans `app/Http/Resources`**. Quand un comportement vous surprend, cherchez d'abord dans le service.

## A.2 ADR — décisions d'architecture

**Contexte.** Plusieurs écrans doivent afficher le même état (file, morceau, progression) alors que les ajouts arrivent de téléphones au réseau capricieux.

1. **Le serveur est la source de vérité de l'état de lecture.** La ligne `rooms` porte `playback_status`, `current_queue_item_id`, `position_ms`, `position_updated_at`. Le lecteur de l'hôte *exécute* cet état. Écarté : faire de l'onglet hôte la référence — un rechargement de page ferait perdre l'état à tout le monde.
2. **Un compteur `version` monotone** est incrémenté à chaque mutation, sous verrou de ligne. Les events sont des *deltas* numérotés ; un client qui voit un trou recharge l'état complet. Écarté : diffuser l'état complet à chaque fois — simple, mais chaque payload grossit avec la file et bute sur la limite de 10 Ko de Reverb.
3. **Canal public `room.{code}` pour l'état, canal privé `private-room.{code}.host` pour l'hôte.** Le code du salon joue le rôle d'invitation : l'état n'est pas secret (la TV l'affiche). Seules les informations réservées à l'hôte passent par le canal privé.
4. **Endpoints JSON pour les actions, Inertia pour les pages.** Une action (ajouter, mettre en pause) ne doit pas recharger des props ni changer d'URL : elle renvoie l'état complet, appliqué immédiatement. Inertia sert uniquement à livrer la page et son état initial sans appel supplémentaire.
5. **Deux familles de routes** : `api.php` sans état (invités, jeton dans un en-tête) et `web.php` avec session + CSRF (hôte).

**Conséquence.** Tout client peut se reconstruire à partir de `GET /api/rooms/{code}/state` ; les events ne sont qu'une optimisation de latence.

## A.3 Installation

Les commandes ci-dessous sont celles qui ont servi à construire le projet. **Vous n'avez pas à les rejouer** : leur résultat est dans le dépôt. Pour lancer le projet, suivez le [README](../README.md).

| Commande | Ce qu'elle fait |
| --- | --- |
| `composer create-project laravel/laravel:^11.0 .` | Squelette Laravel 11 |
| `composer require inertiajs/inertia-laravel:^2.0` | Adaptateur serveur d'Inertia 2 |
| `composer require laravel/socialite socialiteproviders/spotify` | OAuth. Spotify n'est pas un pilote natif de Socialite, d'où le second paquet |
| `composer require laravel/reverb` | Serveur WebSocket |
| `php artisan install:broadcasting` | Publie `config/broadcasting.php`, crée `routes/channels.php` et l'enregistre dans `bootstrap/app.php` |
| `php artisan reverb:install` | Publie `config/reverb.php` et ajoute les variables `REVERB_*` |
| `composer require --dev pestphp/pest:^3.0 pestphp/pest-plugin-laravel:^3.0` | Tests backend |
| `php artisan sail:install --with=pgsql,redis --php=8.3` | Génère le fichier Docker Compose |
| `npm install react react-dom @inertiajs/react@^2 lucide-react qrcode.react laravel-echo pusher-js` | Dépendances frontend. `pusher-js` car Reverb parle le protocole Pusher |
| `npm install -D typescript @types/react @types/react-dom @vitejs/plugin-react@^4 vitest@^3 jsdom @testing-library/react @testing-library/jest-dom @testing-library/user-event @types/node` | Outillage TypeScript et tests |

Fichiers supprimés du squelette car inutilisés : migrations `cache` et `jobs` (le cache est dans Redis, aucune file de jobs), `welcome.blade.php`, `resources/js/app.js` et `bootstrap.js`, tests d'exemple.

### `docker-compose.yml`

**Rôle.** Décrit les cinq conteneurs de développement.

**Détails.**
- `laravel.test` : PHP 8.3 + Node, expose le port 80 (application) et 5173 (Vite).
- `reverb` : **même image**, mais avec une commande (`php artisan reverb:start --host=0.0.0.0 --port=8080`). Le script d'entrée de Sail exécute la commande fournie à la place du serveur web. Alternative écartée : ajouter Reverb au `supervisord` du conteneur principal — il aurait fallu publier et maintenir les fichiers Docker de Sail ; un service séparé se redémarre et se consulte (`sail logs reverb`) indépendamment.
- `scheduler` : même principe avec `php artisan schedule:work`, pour `rooms:close-inactive`.
- Le bloc `x-sail-build` est une ancre YAML réutilisée par les trois services PHP. Sans `build:` sur `reverb`, Docker tenterait de *télécharger* l'image `sail-8.3/app`, qui n'existe que localement.
- `pgsql` : image `postgres:16-alpine`, volume monté sur `/var/lib/postgresql/data` (chemin des images ≤ 17), et script d'initialisation de Sail qui crée la base `testing`.
- `redis` : cache des recherches, sessions, verrous, compteur de quota.

### `.env.example`

**Rôle.** Toutes les variables, avec des valeurs prêtes pour Sail.

**Détails — les trois pièges traités.**
- `APP_URL=http://127.0.0.1` : Spotify refuse `http://localhost` comme URI de redirection.
- **Deux adresses pour Reverb.** `REVERB_HOST=reverb` est l'adresse vue *par le conteneur PHP* (nom du service Docker) pour publier les events. Le *navigateur*, lui, utilise `VITE_REVERB_HOST` ; laissé vide, `lib/echo.ts` prend le nom d'hôte de la page. L'hôte (`127.0.0.1`) et les téléphones (`192.168.x.x`) atteignent ainsi Reverb sans réglage par appareil. Le squelette met par défaut `VITE_REVERB_HOST="${REVERB_HOST}"`, ce qui enverrait les navigateurs vers `reverb`, un nom qu'ils ne savent pas résoudre.
- `JUKEBOX_PUBLIC_URL` : adresse encodée dans le QR code (voir `RoomController::joinUrl`).
- `QUEUE_CONNECTION=sync` : les events sont en `ShouldBroadcastNow`, aucun worker n'est nécessaire.
- `SESSION_LIFETIME=720` : l'hôte reste connecté toute la soirée.

### `vite.config.ts`

**Rôle.** Compilation React/TypeScript et intégration Laravel.

**Détails.** `server.host: '0.0.0.0'` est indispensable dans un conteneur (sinon Vite n'écoute que sur son propre bouclage). `hmr.host: 'localhost'` est l'adresse que le navigateur utilise pour le rechargement à chaud. `watch.usePolling` s'active avec `VITE_USE_POLLING=true` quand le projet est sur un disque Windows monté (`/mnt/c`), où les notifications de fichiers ne remontent pas ; c'est coûteux en CPU, donc désactivé par défaut. L'alias `@` pointe vers `resources/js` (répété dans `tsconfig.json` et `vitest.config.ts`).

### `tsconfig.json`, `tailwind.config.ts`, `resources/css/app.css`, `resources/views/app.blade.php`

- **`tsconfig.json`** : `strict` plus `noUncheckedIndexedAccess` (un accès `tableau[i]` est typé « peut-être indéfini », ce qui a évité plusieurs erreurs dans le réordonnancement). `noEmit` : TypeScript ne fait que vérifier, c'est Vite qui compile.
- **`tailwind.config.ts`** : palette sombre `ink`, couleurs des sources, et point de rupture `ultrawide` (`min-aspect-ratio: 21/9`) pour passer à trois colonnes.
- **`app.css`** : Tailwind, chiffres à chasse fixe (`.tabular`) pour que les durées ne tremblent pas, marge basse respectant l'encoche des téléphones.
- **`app.blade.php`** : unique vue Blade. Elle porte la balise `<meta name="csrf-token">`, lue par Echo pour autoriser le canal privé, et charge le point d'entrée plus le fichier de la page courante.

## A.4 Schéma de données

### Enums — `app/Enums/`

| Fichier | Rôle |
| --- | --- |
| `Provider.php` | `spotify`, `youtube`. Point d'extension : ajouter un cas ici, un `MusicProvider` PHP et un `PlayerAdapter` TS. |
| `QueueItemStatus.php` | `queued → playing → played | skipped`. `active()` renvoie les statuts qui interdisent un doublon. |
| `PlaybackStatus.php` | `idle` (aucun morceau courant), `playing`, `paused`. |
| `RoomStatus.php` | `open`, `closed`. Ajouté aux trois enums demandés : la colonne `rooms.status` avait besoin de valeurs définies. |

**Détails.** Des enums « backed » (chaîne) : la valeur stockée en base est lisible, et le cast Eloquent garantit qu'aucune valeur inconnue ne circule dans le code.

### `app/Casts/AsPreciseDateTime.php`

**Rôle.** Lire et écrire `rooms.position_updated_at` à la milliseconde.

**Détails.** Le cast `datetime` d'Eloquent écrit au format `Y-m-d H:i:s` et perd les millisecondes : la progression aurait jusqu'à une seconde d'erreur. Deux pièges rencontrés et traités :
- PostgreSQL retire les zéros de fin (`…:00.5`), donc la lecture utilise `parse()` plutôt qu'un format strict ;
- le format `v` de PHP **tronque** alors que `getTimestampMs()` **arrondit**. L'écriture passe donc par `getTimestampMs()`, sinon « maintenant − position_updated_at » pouvait valoir 1 ms au lieu de 0 (un test a révélé ce décalage).

Alternative écartée : stocker un entier (ms epoch). Plus simple à calculer, mais illisible dans un client SQL.

### Migrations — `database/migrations/`

| Fichier | Points à retenir |
| --- | --- |
| `…000000_create_users_table.php` | Pas d'e-mail ni de mot de passe. Jetons en `text` car chiffrés (bien plus longs que le jeton brut). `spotify_id` unique. |
| `…000001_create_rooms_table.php` | `code` unique sur 4 caractères ; `position_updated_at` en `timestamp(3)` ; `version` en bigint ; index `(status, last_activity_at)` pour la commande de fermeture. |
| `…000002_create_guests_table.php` | `token` unique sur 40 caractères ; suppression en cascade avec le salon. |
| `…000003_create_tracks_table.php` | Index unique `(provider, provider_track_id)`. |
| `…000004_create_queue_items_table.php` | Index `(room_id, status, position)` ; crée ensuite la clé étrangère `rooms.current_queue_item_id`. |

**Détails.** `rooms` et `queue_items` se référencent mutuellement. La colonne `current_queue_item_id` est donc créée sans contrainte, et la clé étrangère est ajoutée dans la dernière migration, une fois les deux tables présentes. `nullOnDelete` partout où la disparition d'une ligne ne doit pas en supprimer une autre (un invité supprimé laisse ses morceaux dans la file).

Il n'y a **pas** d'index unique sur `(room_id, position)` : réordonner exigerait des contraintes différées. L'unicité des positions est garantie par le verrou du salon (voir `QueueService`), et vérifiée par les tests.

### Modèles — `app/Models/`

**`User.php`** — l'hôte. Casts `encrypted` sur les deux jetons : une fuite de la base ne donne pas accès aux comptes Spotify (il faut aussi `APP_KEY`). `$hidden` empêche les jetons de partir dans une réponse. `getRememberTokenName()` renvoie une chaîne vide : la table n'a pas de colonne `remember_token`, et sans cela la déconnexion tenterait de la lire. `hasSpotifyPremium()` compare `spotify_product` à `premium`.

**`Room.php`** — le salon et son état de lecture.
- `getRouteKeyName()` renvoie `code` : les URL n'exposent jamais l'identifiant interne.
- `queue()` : relation filtrée sur `queued`, triée par position — la file *visible*.
- `bumpVersion()` : `version++` et `last_activity_at = now()`. À appeler dans la transaction qui détient le verrou.
- `currentPositionMs()` : position extrapolée côté serveur (`position_ms` + temps écoulé si lecture en cours).
- `loadState()` : charge en une fois toutes les relations nécessaires à la sérialisation. `load` et non `loadMissing`, pour obtenir des relations fraîches après une mutation.
- `$attributes` fixe les valeurs par défaut côté PHP : un salon tout juste créé est cohérent sans relecture en base.

**`Guest.php`** — `generateToken()` : 40 caractères aléatoires (`Str::random`, source cryptographique). `token` est dans `$hidden` : il n'est renvoyé qu'une fois, explicitement, à la création.

**`Track.php`** — morceau normalisé, cast de `provider` vers l'enum.

**`QueueItem.php`** — relations `track`, `guest` (via `added_by_guest_id`), `user` (via `added_by_user_id`).

### Factories — `database/factories/`

`UserFactory` (états `free()`, `expiringToken()`), `RoomFactory` (états `closed()`, `inactiveFor()` ; le code vient du vrai générateur), `GuestFactory`, `TrackFactory` (YouTube par défaut, état `spotify()`), `QueueItemFactory`.

**Détails.** Dans les tests, la file est remplie par `QueueService::add()` et non par `QueueItemFactory` : c'est le service qui attribue les positions, et c'est lui qu'on veut exercer.

### `database/seeders/DatabaseSeeder.php`

**Rôle.** Un salon de démonstration avec trois morceaux, pour regarder la télécommande invité sans clés d'API. L'hôte créé est fictif : on ne peut pas se connecter avec lui, seule la page invité est consultable.

## A.5 Codes de salon et fermeture automatique

### `app/Services/RoomCodeGenerator.php`

**Rôle.** Produire un code de 4 lettres inutilisé.

**Détails.**
- Alphabet de 24 lettres, sans `I` ni `O` (confondus avec `1` et `0` sur une TV). Aucun chiffre n'est utilisé, donc `0` et `1` sont exclus d'office. 24⁴ = 331 776 codes.
- `random_int()` : générateur cryptographique, sans biais.
- `generate()` teste l'existence en base, 50 essais au plus. **Ce test n'est pas atomique** : deux créations simultanées peuvent tirer le même code. C'est l'index unique qui tranche, et `RoomService::create()` réessaie.
- Le constructeur accepte une fonction de tirage : c'est ce qui rend les collisions testables de façon déterministe.

### `app/Services/RoomService.php`

**Rôle.** Créer et fermer les salons.

**Détails.** `create()` enveloppe l'insertion dans `DB::transaction()` : à l'intérieur d'une transaction existante, cela pose un `SAVEPOINT`. Sous PostgreSQL, une violation d'unicité rend sinon toute la transaction inutilisable — sans ce point de sauvegarde, le « réessai » échouerait aussi. `closeInactive()` parcourt avec `chunkById` : mémoire constante, et insensible au fait qu'on modifie la colonne filtrée pendant le parcours. Un salon jamais utilisé (`last_activity_at` nul) est jugé sur sa date de création.

### `app/Console/Commands/CloseInactiveRooms.php` et `routes/console.php`

**Rôle.** `php artisan rooms:close-inactive [--hours=12]`, planifiée toutes les heures avec `withoutOverlapping()`.

**Détails.** Exécutée toutes les heures, elle ferme un salon au plus tard 13 h après sa dernière activité. « Activité » = toute mutation de la file ou de la lecture (`bumpVersion`).

### `config/jukebox.php` et `config/services.php`

**Rôle.** Réglages propres à l'application (URL publique, seuil d'inactivité, paramètres de recherche) et identifiants des services tiers. Aucune valeur sensible en dur.

## Point de contrôle A

```bash
./vendor/bin/sail up -d
```

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

Vous devez pouvoir :
- voir les cinq services à l'état `running` avec `./vendor/bin/sail ps` ;
- lire « Salon de démo créé : XXXX » en sortie du seed, avec un code de 4 lettres sans I ni O ;
- ouvrir `http://127.0.0.1` et voir la page d'accueil (après `sail npm install` et `sail npm run dev`) ;
- inspecter le schéma : `./vendor/bin/sail artisan db:table rooms` montre `code` unique et `version` en bigint ;
- exécuter `./vendor/bin/sail artisan rooms:close-inactive` et lire « 0 salon(s) fermé(s) ».
