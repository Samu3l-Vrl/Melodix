# D — Frontend

TypeScript strict, aucun `any`. Les fichiers contenant du JSX sont en `.tsx`, les modules sans JSX en `.ts`.

## D.1 Types

### `resources/js/types/index.ts`

**Rôle.** Les types partagés, alignés un pour un sur les API Resources PHP.

**Détails.** Chaque type cite en commentaire la classe PHP correspondante. `QueueUpdatedEvent` est une **union discriminée** sur `action` : dans un `switch`, TypeScript sait que `event.item` existe pour `added` et `event.order` pour `reordered`, et signale un cas oublié. `PlaybackCommand` décrit de la même façon le corps de `POST …/playback` : impossible d'envoyer un `seek` sans `position_ms`.

Alternative écartée : générer les types depuis PHP (`spatie/laravel-typescript-transformer`). Utile sur un grand projet ; ici sept types tiennent dans un fichier et un test PHP vérifie la structure de l'état.

### `resources/js/types/spotify.d.ts`, `youtube.d.ts`, `vite-env.d.ts`

**Rôle.** Déclarations des deux SDK chargés par balise `<script>` (ils n'ont pas de paquet npm officiel), et des variables `VITE_*`.

**Détails.** Seule la partie réellement utilisée de chaque API est décrite : c'est ce qui permet de se passer de `any` sans dépendre de paquets `@types` tiers.

## D.2 Modules sans React — `resources/js/lib/`

### `lib/api.ts`

**Rôle.** Client HTTP typé pour tous les endpoints JSON.

**Détails.**
- Basé sur `fetch`, sans dépendance. Chaque méthode renvoie le type attendu (`Promise<RoomState>`, etc.).
- `Accept: application/json` : sans cet en-tête, Laravel répondrait aux erreurs par une redirection HTML.
- **CSRF** : le cookie `XSRF-TOKEN` posé par Laravel est renvoyé dans `X-XSRF-TOKEN` pour les requêtes non GET — c'est ce qui protège les actions de l'hôte.
- `ApiError` porte `status` et `code` (`duplicate_track`, `queue_conflict`…). Les composants décident d'après le `code`.
- L'annulation volontaire (`AbortError`) remonte telle quelle, pour être ignorée par l'appelant.

### `lib/echo.ts`

**Rôle.** Connexion Reverb et abonnements. **Seul module à connaître Echo et Pusher.**

**Détails.**
- Le reste de l'application ne voit que trois fonctions typées : `subscribeToRoom`, `subscribeToHost`, `onConnectionChange`. Les tests des hooks simulent ce module en quelques lignes.
- Une seule connexion par onglet, créée au premier appel.
- `wsHost: VITE_REVERB_HOST || window.location.hostname` : les téléphones atteignent Reverb sur l'adresse par laquelle ils ont ouvert la page.
- Le point devant `.queue.updated` indique à Echo de ne pas préfixer le nom par l'espace de noms PHP.
- `toConnectionState()` ramène les six états de pusher-js à trois : `connected`, `reconnecting` (`connecting`), `offline` (`unavailable`, `failed`, `disconnected`).

### `lib/roomReducer.ts`

**Rôle.** Appliquer un event à l'état local, par version.

**Détails.** Des **fonctions pures** : elles reçoivent un état et un event, renvoient un nouvel état et un verdict (`applied`, `ignored`, `gap`). Toute la logique du contrat de versions est donc testable sans React ni réseau. L'immutabilité (nouveaux tableaux, nouvel objet) est nécessaire : React compare par référence. `applySnapshot()` refuse un état complet plus ancien que l'état local — le cas d'une réponse de resynchronisation arrivée après un event plus récent.

### `lib/time.ts`

**Rôle.** `computePositionMs()` (position extrapolée) et `formatDuration()`.

**Détails.** La position est *recalculée à partir de l'horloge* à chaque appel, jamais incrémentée. Un onglet en arrière-plan ou un téléphone en veille gèle les minuteries ; avec un compteur, la barre aurait du retard au réveil. L'horloge locale est injectable, ce qui rend la fonction testable.

### `lib/guestSession.ts`

**Rôle.** Jeton et pseudo de l'invité dans le `localStorage`, une entrée par salon.

**Détails.** Chaque accès est protégé : le stockage peut être indisponible (navigation privée de certains navigateurs). Le contenu lu est validé avant usage. Le dernier pseudo est mémorisé à part, pour préremplir le formulaire d'un autre salon.

## D.3 Hooks — `resources/js/hooks/`

### `hooks/useRoomState.ts`

**Rôle.** L'état temps réel d'un salon, commun aux deux écrans.

**Signature.** `useRoomState(code, initial)` renvoie `{ state, connection, clockOffsetMs, applyServerState, resync }`.

**Détails.**
- **`stateRef`** : les callbacks d'events sont créés une seule fois. Sans référence toujours à jour, ils compareraient les versions à un état périmé — le piège classique des fermetures avec `useState`.
- **Un seul resync à la fois** (`resyncingRef`) : une rafale d'events en avance ne déclenche qu'un rechargement.
- **Nouvelle tentative** 3 s après un échec réseau.
- Rechargement à la première connexion (les props peuvent dater de quelques centaines de millisecondes), à chaque reconnexion, et au retour de l'onglet au premier plan.
- `applyServerState()` applique l'état renvoyé par une action. L'event de la même mutation arrive ensuite avec la même version : il est ignoré, donc pas de doublon dans la file.
- `clockOffsetMs` retient le plus grand décalage observé (voir section C).

### `hooks/usePlaybackPosition.ts`

**Rôle.** Position de lecture animée.

**Détails.** `requestAnimationFrame` plutôt que `setInterval` : calé sur l'affichage, et suspendu par le navigateur quand l'onglet est caché. Comme la position est recalculée depuis l'horloge, cette suspension ne crée aucune dérive. Hors lecture, aucune boucle ne tourne.

### `hooks/useDebouncedValue.ts`

**Rôle.** Ne renvoyer une valeur qu'une fois qu'elle a cessé de changer.

**Détails.** Chaque frappe annule la minuterie précédente. « daft punk » tapé d'une traite produit une requête, pas neuf.

## D.4 Lecteurs — `resources/js/player/`

### `player/PlayerAdapter.ts`

**Rôle.** Le contrat commun à tous les lecteurs.

**Détails.** `load`, `play`, `pause`, `seek`, `destroy`, `onProgress`, `onEnded`, `onError`, plus `prepare()` facultatif (initialisation pendant un geste utilisateur). `PlayerError` classe les erreurs indépendamment de la source (`unplayable`, `auth`, `init`, `provider_unavailable`, `playback`). `Emitter` est un petit émetteur typé qui évite de réécrire la gestion des écouteurs dans chaque adaptateur.

Répartition des erreurs : celles du chargement passent par le **rejet de la promesse** de `load()` ; celles qui surviennent après passent par `onError`.

### `player/loadScript.ts`

**Rôle.** Charger un script externe une seule fois.

**Détails.** Les appels suivants renvoient la même promesse. Un échec est oublié, pour permettre une nouvelle tentative (bloqueur de publicité désactivé entre-temps).

### `player/YouTubeAdapter.ts`

**Rôle.** Lecture via l'IFrame Player API officielle.

**Détails.**
- **Conformité** : le lecteur est visible (le conteneur fait au moins 200×200, garanti par la page hôte), les commandes natives sont conservées (`controls: 1`), il n'y a ni extraction audio ni contournement des publicités.
- L'API **remplace** l'élément fourni par une `<iframe>`. On lui donne un enfant jetable, pour que le conteneur géré par React reste intact.
- Pas d'event de progression dans l'API : `getCurrentTime()` est interrogé toutes les 500 ms, uniquement pendant la lecture.
- Fin : état `ENDED` (0). Erreurs : les codes 100, 101 et 150 (vidéo introuvable ou intégration interdite) deviennent `unplayable`.
- `cueVideoById` quand le salon est en pause, `loadVideoById` sinon.

### `player/SpotifyAdapter.ts`

**Rôle.** Lecture via le Web Playback SDK.

**Détails.**
- Le SDK crée un « appareil » Spotify Connect dans l'onglet. On y lance un morceau avec `PUT /v1/me/player/play?device_id=…`.
- `uris: [un seul morceau]` : pas de contexte (album, playlist), donc Spotify n'enchaîne rien. **C'est le serveur qui décide du morceau suivant.**
- `getOAuthToken` appelle `GET /host/spotify/token` : le SDK redemande un jeton à chaque expiration et en reçoit toujours un valide.
- `prepare()` appelle `activateElement()` pendant le clic « Démarrer la session ».
- **Détection de fin** : le SDK n'a pas d'event dédié. Quand un morceau lancé seul se termine, le lecteur repasse en pause à la position 0 et le morceau apparaît dans `previous_tracks`. On exige en plus que la lecture ait réellement commencé, pour ne pas confondre avec l'état transitoire observé juste après le chargement. C'est une heuristique documentée par la communauté, **à valider avec un vrai compte Premium**.
- Erreurs `initialization_error` (navigateur sans DRM, contexte non sécurisé), `authentication_error`, `account_error` (compte non Premium).

### `player/PlayerManager.ts`

**Rôle.** Choisir l'adaptateur selon `track.provider` et garantir qu'un seul lecteur joue.

**Détails.**
- Les adaptateurs sont créés à la demande, à partir de fabriques. Sans hôte Premium, aucune fabrique Spotify n'est fournie : un morceau Spotify est signalé `provider_unavailable` et sauté.
- **Bascule** : avant de charger un morceau d'une autre source, le lecteur précédent est mis en pause.
- **Un seul signal par morceau** (`settledKey`) : un double « ended », ou une erreur suivie d'un « ended », n'avance pas la file deux fois.
- **Les events d'un adaptateur inactif sont ignorés** : un lecteur mis en pause par une bascule peut encore émettre.
- **`loadSequence`** : si l'hôte passe au suivant pendant un chargement lent, l'échec tardif du premier est ignoré.
- `load()` ne rejette jamais : toute erreur devient `onTrackError`.
- Un échec de `play()` fait sauter le morceau ; un échec de `pause()` ou `seek()` est ignoré.

Le gestionnaire **ne choisit pas** le morceau suivant. Il signale « terminé » ou « illisible » ; la page hôte prévient le serveur, qui diffuse le nouvel état.

## D.5 Pages — `resources/js/Pages/`

### `app.tsx`

**Rôle.** Point d'entrée Inertia.

**Détails.** `import.meta.glob` crée un fichier JavaScript par page : le téléphone d'un invité ne télécharge jamais le code de l'écran hôte. `createRoot` et non `hydrateRoot`, puisque le rendu côté serveur est désactivé.

### `Pages/Home.tsx`

**Rôle.** Rejoindre un salon par code, ou se connecter avec Spotify et en créer un.

**Détails.** Le lien OAuth est une balise `<a>` classique et non un `<Link>` Inertia : la redirection quitte le site. Le champ de code met en majuscules et valide avec le même alphabet que le serveur. Un avertissement s'affiche si le compte n'est pas Premium.

### `Pages/Host/HostView.tsx`

**Rôle.** L'écran TV/PC.

**Détails — principe.** La page ne décide de rien. Chaque action part au serveur ; c'est l'état renvoyé ou diffusé qui pilote le lecteur. La fonction `run()` centralise l'envoi d'une action, l'application de l'état reçu et la gestion des erreurs.

- **« Démarrer la session »** : les navigateurs interdisent de lancer du son sans geste de l'utilisateur. Ce clic est ce geste ; il crée le `PlayerManager` et initialise les lecteurs.
- **Réconciliation** (un `useEffect` sur le morceau courant et le statut) : nouveau morceau → `load()` à la position calculée ; même morceau → `play()` ou `pause()`. Après un rechargement de page en pleine lecture, la reprise se fait à la bonne position.
- **Démarrage automatique** : session lancée, rien ne joue, file non vide → un `play` est demandé, une seule fois par version d'état.
- **Correction de dérive** : le lecteur réel s'écarte de l'horloge du serveur (mise en mémoire tampon, publicité YouTube). Au-delà de 2,5 s d'écart, la position réelle est envoyée (`seek`), au plus toutes les 3 s. Les barres des invités restent justes.
- **Mise en page** : grille à deux colonnes en 16:9 (lecteur à gauche, file et QR code à droite), trois colonnes en ultrawide. Le QR code et le code restent affichés en permanence.
- **Lecteur YouTube** : son conteneur est toujours dans le DOM, masqué quand le morceau courant n'est pas un clip.
- **QR code** : `QRCodeSVG` sur fond blanc avec marge, indispensable à la lecture par les appareils photo.
- Canal privé : un message « … a rejoint la soirée » et le nombre d'invités.

### `Pages/Guest/GuestView.tsx`

**Rôle.** La télécommande mobile.

**Détails.**
- **Trois phases** : `checking` (un jeton stocké est en cours de validation), `nickname`, `ready`.
- **Reprise automatique** : `GET …/guests/me`. Sur 401 le jeton est effacé et le pseudo prérempli ; sur erreur réseau on fait confiance au jeton stocké (le premier appel protégé tranchera).
- **Recherche** : debounce de 400 ms, minimum 3 caractères, et un `AbortController` annule la requête précédente — seule la réponse à la dernière saisie s'affiche, même si une plus ancienne arrive après.
- **Bouton d'ajout à cinq états** : `idle`, `loading` (désactivé, pas de double ajout), `added`, `duplicate` (409), `error` (cliquable pour réessayer). `resolveAddStatus()` combine l'état local et l'état temps réel : un morceau ajouté par un autre invité apparaît « Ajouté » d'emblée. Quand un morceau quitte le salon, son bouton redevient « Ajouter ».
- Un 409 inattendu signifie que la file locale est en retard : la page se resynchronise.
- **Lecture seule** : aucun contrôle de lecture, et la barre de progression n'est pas un curseur.
- **Mobile** : zones tactiles de 44 px, champs en 16 px (en dessous, iOS zoome au focus), en-tête collant, marge basse respectant l'encoche.

## D.6 Composants — `resources/js/Components/`

| Fichier | Rôle | Détail |
| --- | --- | --- |
| `TrackRow.tsx` | Ligne d'un morceau | Accepte un `SearchResult` ou un `Track`. Emplacement `actions` pour les boutons : le même composant sert à la recherche, à la file de l'hôte et à celle de l'invité. |
| `ProviderBadge.tsx` | Pastille Spotify / YouTube | Classes Tailwind écrites en toutes lettres : Tailwind ne détecte pas les noms construits dynamiquement. |
| `ConnectionStatus.tsx` | État de la connexion | `role="status"` : annoncé par les lecteurs d'écran. Icône et libellé, jamais la couleur seule. |
| `ProgressBar.tsx` | Barre de progression | `slider` (clic, flèches) si `onSeek` est fourni, simple `progressbar` sinon. Animée par `transform`, sans recalcul de mise en page. |

## Point de contrôle D

```bash
./vendor/bin/sail npm run typecheck
```

```bash
./vendor/bin/sail npm run build
```

Les deux commandes se terminent sans erreur. Puis, avec `./vendor/bin/sail npm run dev` :

1. Écran hôte : le voile « Démarrer la session » apparaît ; après le clic, l'indicateur affiche « Connecté ».
2. Redimensionnez la fenêtre : deux colonnes en 16:9, trois au-delà de 21:9.
3. Page invité en mode mobile : tapez deux caractères (« Tapez au moins 3 caractères »), puis un troisième. Dans l'onglet Réseau, **une seule** requête `search` part, 400 ms après la dernière frappe.
4. Ajoutez un morceau : le bouton passe par le chargement puis « Ajouté », et la lecture démarre sur l'hôte.
5. Ajoutez une vidéo YouTube : le lecteur apparaît à la place de la pochette.
6. Rechargez la page invité : pas de nouvelle demande de pseudo.
7. Rechargez l'écran hôte en pleine lecture, relancez la session : la lecture reprend là où elle en était.
