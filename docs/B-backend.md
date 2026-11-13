# B — Backend

## B.1 Authentification de l'hôte

### `app/Http/Controllers/Auth/SpotifyAuthController.php`

**Rôle.** Flux OAuth « Authorization Code » avec Spotify : redirection, retour, déconnexion.

**Détails.**
- `redirect()` utilise `setScopes()` (et non `scopes()`, qui *ajoute* aux scopes par défaut du pilote) pour demander exactement cinq scopes : `streaming` (lecture dans le navigateur), `user-read-email` et `user-read-private` (exigés par le SDK ; c'est de là que vient le champ `product`), `user-read-playback-state` et `user-modify-playback-state` (lancer un morceau sur l'appareil du SDK).
- `callback()` : Socialite vérifie le paramètre `state` (protection CSRF du flux), échange le code contre des jetons et lit le profil. `User::updateOrCreate` par `spotify_id` : `spotify_product` est réévalué à chaque connexion, car l'hôte a pu passer Premium entre-temps. `session()->regenerate()` protège contre la fixation de session.
- Toute erreur (refus de l'hôte, `state` invalide, Spotify indisponible) est journalisée et ramène à l'accueil avec un message, plutôt qu'une page d'erreur.
- Il n'y a aucun autre moyen de se connecter : pas de mot de passe applicatif.

L'enregistrement du pilote Spotify se fait dans `AppServiceProvider::boot()` via l'event `SocialiteWasCalled`.

### `app/Services/Spotify/SpotifyTokenService.php`

**Rôle.** Fournir un jeton d'accès valide pour l'hôte, en le rafraîchissant si besoin.

**Détails.**
- `needsRefresh()` : vrai si le jeton expire dans moins de 60 secondes. Cette marge laisse au jeton le temps d'arriver jusqu'au SDK et d'être utilisé.
- **Verrou `Cache::lock` par hôte.** Le SDK et l'adaptateur peuvent demander un jeton au même instant. Spotify peut faire tourner le refresh token : deux rafraîchissements simultanés risqueraient d'enregistrer un jeton déjà invalidé par l'autre. Sous verrou, l'utilisateur est relu, et le second appel constate que le travail est fait.
- Spotify ne renvoie pas toujours un nouveau refresh token : on garde l'ancien dans ce cas.
- Un refus (`invalid_grant`, accès révoqué) lève `SpotifyAuthException` → HTTP 401 avec le code `spotify_reauth_required`.

### `app/Http/Controllers/Host/SpotifyTokenController.php`

**Rôle.** `GET /host/spotify/token`, appelé par le callback `getOAuthToken` du Web Playback SDK.

**Détails.** Seul le jeton d'accès (courte durée) atteint le navigateur ; le refresh token ne quitte jamais le serveur. En-tête `Cache-Control: no-store` : un jeton ne doit pas rester dans un cache.

## B.2 Salons

### `app/Http/Controllers/HomeController.php`

**Rôle.** Page d'accueil ; liste les salons encore ouverts de l'hôte connecté, pour reprendre une soirée.

### `app/Http/Controllers/RoomController.php`

**Rôle.** Création d'un salon, pages Inertia hôte et invité, fermeture.

**Détails.**
- Les pages reçoivent l'état complet en props (`RoomStateResource::plain`) : aucun appel réseau au premier rendu.
- `host()` appelle `Gate::authorize('control', $room)` : 403 pour tout autre utilisateur.
- `joinUrl()` construit l'adresse du QR code à partir de `JUKEBOX_PUBLIC_URL`. L'hôte ouvre l'application sur `127.0.0.1` (imposé par Spotify) : utiliser l'URL courante produirait un QR code inutilisable par les téléphones.

### `app/Policies/RoomPolicy.php`

**Rôle.** Une seule règle, `control` : le salon appartient à l'utilisateur.

**Détails.** Laravel associe la policy au modèle par convention de nommage. Elle est appelée depuis les Form Requests de l'hôte (`authorize()`) et par `Gate::authorize` dans les actions sans Form Request.

### `app/Http/Middleware/EnsureRoomIsOpen.php` (alias `room.open`)

**Rôle.** Refuser les requêtes visant un salon fermé : 410 avec le code `room_closed` pour le JSON, 404 pour les pages.

**Détails.** C'est un premier filtre peu coûteux. Les services revérifient **sous verrou** : un salon peut être fermé entre ce contrôle et la mutation.

### `app/Http/Middleware/HandleInertiaRequests.php`

**Rôle.** Vue racine et props partagées (`app.name`, `auth.user`, `flash.error`).

**Détails.** Ajouté au seul groupe `web` : les routes `/api` restent du JSON pur. `auth.user` passe par `HostResource`, qui n'expose aucun jeton.

## B.3 Invités

### `app/Http/Controllers/Api/GuestController.php`

**Rôle.** `POST /api/rooms/{code}/guests` (pseudo → jeton) et `GET …/guests/me` (validation d'un jeton stocké).

**Détails.** Le jeton est renvoyé une seule fois, dans cette réponse. La création diffuse `GuestJoined` sur le canal privé de l'hôte. `me` sert à la reprise automatique : 200 = le jeton du `localStorage` est encore bon, 401 = redemander un pseudo.

### `app/Http/Middleware/ResolveGuest.php` (alias `guest.token`)

**Rôle.** Authentifier un invité par l'en-tête `X-Guest-Token`.

**Détails.**
- La longueur du jeton est vérifiée avant toute requête SQL.
- Le jeton doit appartenir **à ce salon** (`where room_id`) : un jeton obtenu dans un salon ne vaut rien dans un autre.
- `last_seen_at` n'est écrit qu'une fois par minute : sans cela, chaque frappe de recherche déclencherait un `UPDATE`.
- L'invité résolu est placé dans `$request->attributes`.

Alternative écartée : Sanctum. Il apporte des jetons liés à des utilisateurs avec capacités et expiration ; ici un invité n'est pas un utilisateur, et une simple colonne suffit.

### `app/Http/Requests/StoreGuestRequest.php`

**Rôle.** Valider le pseudo (1 à 24 caractères).

**Détails.** `prepareForValidation()` nettoie les espaces *avant* la validation : un pseudo composé d'espaces devient vide et est refusé.

## B.4 Recherche multi-source

### `app/Services/Music/MusicProvider.php`

**Rôle.** Le contrat d'une source musicale.

**Détails.** La recherche est coupée en deux méthodes — `searchRequest()` *décrit* la requête HTTP, `parseSearchResponse()` *interprète* la réponse — pour que `SearchService` puisse envoyer toutes les sources en parallèle dans un seul `Http::pool`. Avec une méthode unique `search()`, chaque source ferait son appel l'une après l'autre. `find()` récupère un morceau par identifiant, pour l'ajout en file.

### `app/Data/TrackData.php`, `ProviderRequest.php`, `SearchOutcome.php`

**Rôle.** Objets immuables (`readonly`) échangés entre les services : un morceau normalisé, une requête à envoyer, un résultat de recherche avec l'état de chaque source.

### `app/Services/Music/SpotifyProvider.php`

**Rôle.** Recherche dans le catalogue Spotify avec un jeton d'**application** (flux client credentials).

**Détails.**
- Les invités cherchent sans jamais toucher au jeton personnel de l'hôte.
- Le jeton est mis en cache jusqu'à 60 s avant son expiration ; un 401 le fait oublier.
- `market` est toujours envoyé : sans lui, un jeton d'application renvoie des morceaux qui peuvent être illisibles dans le pays de l'hôte. Les morceaux marqués `is_playable: false` sont écartés.
- La première image d'album (la plus grande) est retenue, pour l'affichage TV.

### `app/Services/Music/YouTubeQuota.php`

**Rôle.** Comptabilité locale du quota YouTube.

**Détails.** Google ne fournit pas d'endpoint « quota restant ». On tient donc un compteur Redis, remis à zéro à minuit heure du Pacifique (comme le quota Google), pour s'arrêter **avant** le refus et garder une marge (`YOUTUBE_DAILY_QUOTA=9500`). `Cache::add` puis `Cache::increment` : l'incrément est atomique côté Redis.

### `app/Services/Music/YouTubeProvider.php`

**Rôle.** Recherche de clips via YouTube Data API v3.

**Détails.**
- `search.list` coûte 100 unités : `videoEmbeddable=true` écarte d'emblée les vidéos non intégrables, `videoCategoryId=10` restreint à la catégorie Musique.
- `search.list` ne renvoie pas les durées : **un seul** `videos.list` groupé (1 unité, jusqu'à 50 identifiants) les récupère.
- `parseIsoDuration()` convertit `PT4M13S` en millisecondes avec `DateInterval`. Une durée nulle (`P0D`, direct en cours) écarte la vidéo.
- Les titres de `search.list` sont encodés en entités HTML (`&amp;`, `&#39;`) : ils sont décodés. Le suffixe « - Topic » des chaînes auto-générées est retiré.
- Un 403 `quotaExceeded` marque le budget du jour comme épuisé.
- Aucune extraction audio : seules des métadonnées sont demandées, la lecture passe par le lecteur officiel.

### `app/Services/Music/ProviderRegistry.php`

**Rôle.** L'annuaire des sources, et la seule classe qui sait lesquelles sont utilisables dans un salon.

**Détails.** `isEnabledFor()` : la source doit être configurée, et Spotify exige un hôte Premium. Cette règle unique sert à trois endroits : le filtrage de la recherche, le refus à l'ajout, et le champ `spotify_enabled` de l'état. L'ordre d'enregistrement (dans `AppServiceProvider`) fixe la priorité à l'entrelacement.

### `app/Services/Music/SearchService.php`

**Rôle.** Recherche unifiée.

**Détails — déroulé.**
1. **Normalisation** de la requête (casse, espaces) : « Daft  PUNK » et « daft punk » partagent la même entrée de cache.
2. **Cache par source** (1 h). Une source en cache n'est pas rappelée.
3. **`Http::pool`** pour les sources restantes, timeout 4 s : la latence est celle de la source la plus lente, pas la somme.
4. **Dégradation gracieuse** : chaque source est traitée dans son propre `try/catch`. Dans un pool, une erreur réseau n'est pas levée mais *renvoyée à la place de la réponse* — le code teste donc `instanceof Throwable`. Un échec n'est **jamais mis en cache**.
5. **Entrelacement** (1er Spotify, 1er YouTube, 2e Spotify…) : chaque source classe déjà par pertinence, alterner garde le meilleur de chacune en tête.
6. **Dédoublonnage** : par identifiant, et par « empreinte » artiste + titre entre sources différentes.

L'empreinte (`fingerprint()`) est une **heuristique assumée** : minuscules, sans accents, sans contenu entre parenthèses, sans les mots « official video », mots uniques triés. Elle reconnaît « One More Time / Daft Punk » et « Daft Punk - One More Time (Official Video) ». Au sein d'une même source, les variantes (Radio Edit, Live) sont conservées.

Chaque résultat est aussi mis en cache individuellement (`track:{source}:{id}`) : c'est ce qui permet l'ajout sans rappeler la source.

### `app/Services/Music/TrackResolver.php`

**Rôle.** Transformer (source, identifiant) en ligne `tracks`.

**Détails.** **Le client n'envoie que l'identifiant.** Titre, artiste et durée viennent toujours du serveur : base, puis cache de recherche, puis API de la source. Un invité ne peut donc pas afficher un faux titre sur l'écran en forgeant sa requête. `createOrFirst` gère deux ajouts simultanés d'un morceau inconnu : le second `INSERT` échoue sur l'index unique et relit la ligne du premier.

### `app/Http/Controllers/Api/SearchController.php` et `app/Http/Requests/SearchRequest.php`

**Rôle.** `GET /api/rooms/{code}/search?q=`.

**Détails.** Le minimum de 3 caractères est aussi appliqué côté client, mais c'est ici qu'il protège réellement le quota. La réponse contient `sources` (`ok` / `error` / `disabled`) : l'interface peut afficher « YouTube indisponible » au lieu de laisser croire qu'il n'y a aucun résultat.

## B.5 File d'attente

### `app/Services/QueueService.php`

**Rôle.** Toutes les mutations de la file.

**Détails — la règle centrale.** Chaque méthode ouvre une transaction qui commence par `SELECT … FOR UPDATE` sur la ligne du salon. Les mutations d'un même salon sont donc strictement sérialisées :
- deux ajouts simultanés ne peuvent pas lire le même `max(position)` ;
- deux ajouts du même morceau ne peuvent pas passer tous les deux le test de doublon ;
- `version` augmente d'exactement 1 par mutation.

La diffusion a lieu **après le commit** : on n'annonce jamais un état qui serait annulé.

Pourquoi verrouiller la ligne `rooms` plutôt qu'utiliser un verrou Redis ? Le verrou de ligne est libéré automatiquement à la fin de la transaction, même en cas de plantage, et il protège exactement les données concernées.

| Méthode | Comportement |
| --- | --- |
| `add()` | Refuse le doublon (`queued` ou `playing`) → `DuplicateTrackException` (409). Position = `max + 1` sur tous les éléments du salon : les positions ne sont jamais réutilisées. |
| `remove()` | L'élément est relu sous verrou ; s'il n'est plus `queued` → `QueueConflictException` (409). |
| `reorder()` | La liste reçue doit contenir exactement les identifiants en attente, sinon 409 et le client se resynchronise. Les positions *existantes* sont redistribuées dans le nouvel ordre. |
| `advance()` | Clôt le morceau courant (`played` ou `skipped`), démarre le suivant, ou passe à `idle`. |

**Idempotence de `advance()`.** Le client envoie l'identifiant du morceau qu'il croit en cours. S'il ne correspond pas à l'état réel, quelqu'un a déjà avancé : rien n'est fait. Sans cela, un double « ended » du lecteur ou un double-clic sur « suivant » ferait sauter un morceau.

### `app/Exceptions/`

**Rôle.** `JukeboxException` (classe de base) et ses sous-classes : `DuplicateTrackException` (409), `QueueConflictException` (409), `RoomClosedException` (410), `TrackUnavailableException` (422), `SpotifyAuthException` (401). `ProviderException` est interne et n'atteint jamais le client.

**Détails.** Chaque exception porte son statut et un `code` stable. Laravel appelle leur méthode `render()` automatiquement. Le frontend choisit son message d'après le `code`, pas d'après le texte.

### `app/Http/Controllers/Api/QueueController.php` et `app/Http/Requests/AddToQueueRequest.php`

**Rôle.** `POST /api/rooms/{code}/queue`.

**Détails.** L'identifiant est borné à `[A-Za-z0-9_-]`, car il finit dans une URL d'API et une clé de cache. La source est revérifiée (`isEnabledFor`) : la recherche filtre déjà, mais une requête forgée pourrait tenter d'ajouter un morceau Spotify dans un salon sans hôte Premium. La réponse est l'état complet, statut 201.

### `app/Http/Controllers/Host/QueueController.php`, `AdvanceQueueRequest.php`, `ReorderQueueRequest.php`

**Rôle.** Actions de l'hôte : passer, réordonner, retirer.

**Détails.** `AdvanceQueueRequest` exige que `item_id` soit *présent*, même à `null` : un corps vide ne déclenche pas un skip par accident.

### API Resources — `app/Http/Resources/`

| Fichier | Type TypeScript |
| --- | --- |
| `TrackResource.php` | `Track` |
| `QueueItemResource.php` | `QueueItem` (avec `added_by: {type, name}`) |
| `PlaybackStateResource.php` | `PlaybackState` |
| `RoomStateResource.php` | `RoomState` |
| `GuestResource.php` | `Guest` |
| `HostResource.php` | `HostUser` |
| `SearchResultResource.php` | `SearchResult` |

**Détails.** Les méthodes statiques `plain()` renvoient un tableau PHP sans objets imbriqués : la même forme sert aux réponses HTTP, aux props Inertia et aux payloads des events. `RoomStateResource::for()` recharge les relations puis sérialise : c'est le point d'entrée unique qui évite d'oublier un `load()`. `JsonResource::withoutWrapping()` (dans `AppServiceProvider`) supprime l'enveloppe `{"data": …}`, pour que les types TypeScript correspondent exactement. `added_by` n'expose que le type et le nom, jamais d'identifiant ni de jeton.

## B.6 Routes et câblage

### `routes/web.php`

**Rôle.** Pages et actions de l'hôte, avec session et CSRF.

**Détails.** Trois groupes : public (accueil, OAuth, page invité), `auth` (création, écran hôte, jeton), et `/host/rooms/{room}` avec `room.open`. Les actions de l'hôte répondent en JSON mais restent sous protection CSRF.

### `routes/api.php`

**Rôle.** Endpoints sans état, préfixés par `/api`.

**Détails.** `GET …/state` est hors du groupe `room.open` : il répond aussi pour un salon fermé, afin que le client puisse afficher « salon fermé ». Aucune limitation de débit, hors périmètre de la V0.

### `bootstrap/app.php`

**Rôle.** Assemblage de l'application (Laravel 11 n'a plus de Kernel).

**Détails.** Déclare les routes `api` et `channels`, ajoute le middleware Inertia au groupe `web`, enregistre les alias `room.open` et `guest.token`. `redirectGuestsTo(route('home'))` : il n'y a pas de page de connexion ; un visiteur non authentifié est renvoyé à l'accueil, et les appels JSON reçoivent un 401.

### `app/Providers/AppServiceProvider.php`

**Rôle.** Câblage.

**Détails.**
- `ProviderRegistry` en singleton, construit avec les sources de la V0.
- `Route::bind('room', …)` : le paramètre `{room}` est le code, insensible à la casse.
- `Model::shouldBeStrict()` hors production : un chargement paresseux en boucle (N+1) ou un attribut inconnu lève une exception au lieu de passer inaperçu.
- Enregistrement du pilote Spotify de Socialite.

## Point de contrôle B

Avec `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` et `YOUTUBE_API_KEY` renseignés :

1. `http://127.0.0.1:8000` → « Se connecter avec Spotify » → retour à l'accueil avec votre nom.
2. « Créer un salon » → `/room/XXXX/host`. Dans un autre navigateur, la même URL renvoie à l'accueil.
3. Créer un invité et récupérer son jeton :

```bash
curl -s -X POST http://127.0.0.1:8000/api/rooms/XXXX/guests -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"nickname":"Test"}'
```

4. Chercher (remplacez `JETON`) : la réponse contient `results` et `sources`.

```bash
curl -s 'http://127.0.0.1:8000/api/rooms/XXXX/search?q=daft+punk' -H 'Accept: application/json' -H 'X-Guest-Token: JETON'
```

5. Ajouter un résultat, puis le rejouer : 201 la première fois, **409** `duplicate_track` la seconde.

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8000/api/rooms/XXXX/queue -H 'Accept: application/json' -H 'Content-Type: application/json' -H 'X-Guest-Token: JETON' -d '{"provider":"youtube","provider_track_id":"ID_DU_RESULTAT"}'
```

6. Sans jeton, la recherche répond **401** ; avec deux caractères, **422**.
7. Relancer la même recherche : elle répond instantanément (cache), sans nouvel appel aux API.
