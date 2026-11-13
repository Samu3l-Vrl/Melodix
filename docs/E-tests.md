# E — Tests

```bash
./vendor/bin/sail artisan test
```

```bash
./vendor/bin/sail npm test
```

Résultat lors du développement : **145 tests Pest** et **66 tests Vitest**, tous réussis.

## E.1 Configuration des tests backend

### `phpunit.xml`

**Rôle.** Environnement des tests PHP.

**Détails.**
- **PostgreSQL**, base `testing` : mêmes contraintes et mêmes verrous qu'en production. Avec SQLite, `lockForUpdate()` ne génère rien et le test de verrouillage ne prouverait rien.
- `CACHE_STORE=array` : chaque test repart d'un cache vide, sans Redis.
- `BROADCAST_CONNECTION=null` : aucun serveur Reverb n'est nécessaire.
- Fausses clés Spotify et YouTube.

### `tests/TestCase.php`

**Rôle.** Garde-fous communs.

**Détails.** `Http::preventStrayRequests()` : un test qui oublierait `Http::fake()` échoue au lieu d'appeler réellement Spotify ou YouTube et de consommer du quota. `withoutVite()` : les pages se rendent sans `npm run build` préalable.

### `tests/Pest.php`

**Rôle.** Configuration Pest et fonctions utilitaires : `makeRoom()`, `makeGuest()`, `guestHeaders()`, `queueTracks()`.

**Détails.** `RefreshDatabase` exécute chaque test dans une transaction annulée à la fin. `queueTracks()` passe par le vrai `QueueService`.

## E.2 Tests Pest

| Fichier | Ce qui est vérifié |
| --- | --- |
| `Unit/RoomCodeFormatTest.php` | 500 codes tirés : 4 lettres, ni I ni O ; alphabet de 24 lettres ; codes malformés rejetés |
| `Unit/YouTubeDurationTest.php` | `PT4M13S`, heures, `P0D`, chaîne vide, valeur invalide |
| `Feature/RoomCodeTest.php` | 40 salons, 40 codes distincts ; nouveau tirage en cas de collision ; abandon propre ; création par `POST /rooms` ; code insensible à la casse |
| `Feature/ConstraintsTest.php` | Unicité en base : code de salon, `spotify_id`, jeton invité, `(provider, provider_track_id)` ; jetons Spotify chiffrés |
| `Feature/GuestAuthTest.php` | Pseudo → jeton ; pseudo invalide ; jeton absent, trop court, inconnu, d'un autre salon ; reprise de session ; `last_seen_at` au plus une fois par minute ; salon fermé ; notification sur le canal privé |
| `Feature/HostAuthorizationTest.php` | Chaque action hôte : 401 pour un invité, 403 pour un autre hôte ; écran hôte réservé ; jetons Spotify absents du HTML ; URL du QR code |
| `Feature/QueueTest.php` | Ajout, métadonnées résolues côté serveur, doublon 409, positions, FIFO, idempotence de `advance`, retrait, réordonnancement, Spotify refusé sans Premium |
| `Feature/PlaybackTest.php` | Lecture, pause, déplacement, fin ; position extrapolée ; limitation à un `seek` par seconde ; validation |
| `Feature/SearchServiceTest.php` | Entrelacement, dédoublonnage, paramètres envoyés, panne d'une source, cache, quota, filtrage Premium |
| `Feature/BroadcastingTest.php` | Classe, canal, nom et payload de chaque event ; versions de 1 à 11 sans trou |
| `Feature/ResyncTest.php` | État complet après plusieurs mutations ; structure attendue par TypeScript ; aucune donnée sensible ; Reverb injoignable |
| `Feature/ChannelAuthTest.php` | `POST /broadcasting/auth` : propriétaire accepté, autre hôte et invité refusés |
| `Feature/SpotifyAuthTest.php` | Scopes, création et mise à jour de l'hôte, échec OAuth, rafraîchissement du jeton à moins de 60 s |
| `Feature/CloseInactiveRoomsTest.php` | Seuil de 12 h, activité, option `--hours`, planification horaire |

### Points de méthode

**Contraintes d'unicité (`ConstraintsTest`).** Chaque insertion fautive est faite dans `DB::transaction()`. Sous PostgreSQL, une erreur SQL invalide la transaction en cours ; le point de sauvegarde ainsi créé permet au test de continuer. Ces tests prouvent que les garanties viennent de la **base**, pas seulement du code.

**Ajout concurrent (`QueueTest`).** Un test PHPUnit s'exécute dans un seul processus et une seule transaction : il ne peut pas lancer deux requêtes réellement simultanées. Le test vérifie donc deux choses : douze ajouts de trois invités produisent les positions 1 à 12, et **chaque ajout a émis un `SELECT … FOR UPDATE`** sur `rooms` (lu dans le journal des requêtes). C'est ce verrou qui sérialise les requêtes simultanées. En complément, la concurrence réelle a été vérifiée à la main contre un serveur en cours d'exécution (voir [le tableau des vérifications](README.md)) ; vous pouvez la rejouer avec la commande donnée plus bas.

**Recherche (`SearchServiceTest`).** `Http::fake()` simule les quatre endpoints. Pour le timeout, le test lève la `ConnectException` de Guzzle : c'est ce que le pool reçoit réellement, et ce qu'il transforme en erreur renvoyée à la place de la réponse. `Http::assertSentCount()` prouve l'usage du cache et l'arrêt des appels quand le quota est épuisé.

**Events (`BroadcastingTest`).** `Event::fake([QueueUpdated::class, PlaybackStateChanged::class])` intercepte les deux events. Le test central enchaîne onze mutations de natures différentes, puis collecte les versions de tous les events : elles doivent valoir exactement 1 à 11. Un second test vérifie qu'une action refusée ou sans effet n'émet rien et ne consomme pas de version.

**Canal privé (`ChannelAuthTest`).** Le diffuseur `null` des tests autorise tout. Le test bascule donc sur le vrai diffuseur Reverb (la signature est calculée localement, sans réseau) et recharge `routes/channels.php` pour que le canal s'enregistre auprès de lui.

**Temps.** `freezeTime()` et `travel()` rendent les calculs de position déterministes. Pour une colonne précise à la seconde, `freezeSecond()` évite un écart dû aux microsecondes.

### Rejouer le test de concurrence réelle

Créez 30 invités avec la commande `curl` du point de contrôle B (ou un script), placez un jeton par ligne dans `jetons.txt`, choisissez 30 identifiants de morceaux en cache, puis envoyez les 30 requêtes en parallèle avec `xargs -P 30`. Vérifiez ensuite :

```bash
./vendor/bin/sail artisan tinker --execute="dump(DB::selectOne('select count(*) as total, count(distinct position) as distinctes, min(position) as min, max(position) as max from queue_items'))"
```

Les quatre valeurs doivent être `30, 30, 1, 30`. Pour que les requêtes soient réellement traitées en parallèle, le serveur de développement doit avoir plusieurs workers : c'est le rôle de `PHP_CLI_SERVER_WORKERS=4` dans le `.env`.

## E.3 Tests Vitest

### `vitest.config.ts` et `resources/js/__tests__/setup.ts`

**Rôle.** Environnement `jsdom`, alias `@`, matchers `jest-dom`, nettoyage après chaque test (composants démontés, `localStorage` vidé).

**Détails.** Fichier distinct de `vite.config.ts` : les tests n'ont pas besoin du plugin Laravel.

### `resources/js/__tests__/factories.ts`

**Rôle.** Fabriques typées : `makeTrack`, `makeItem`, `makePlayback`, `makeRoomState`, `makeSearchResult`.

### `resources/js/__tests__/roomReducer.test.ts`

**Rôle.** Le contrat de versions sur les fonctions pures.

**Détails.** Version suivante appliquée, version ancienne ignorée (même référence d'état renvoyée), trou signalé sans appliquer le delta ; ajout, retrait, réordonnancement ; ordre incohérent → resynchronisation ; `computePositionMs` (extrapolation, pause, bornes, horloge locale en avance).

### `resources/js/__tests__/useRoomState.test.tsx`

**Rôle.** Le hook dans son ensemble.

**Détails.** Le module `@/lib/echo` est remplacé par un faux qui capture les callbacks : le test « émet » lui-même les events et les changements de connexion. Cas couverts :
- ordre des versions, events anciens ignorés ;
- trou de version → **le delta n'est pas appliqué**, l'état complet est rechargé ;
- une rafale d'events en avance ne déclenche qu'un rechargement ;
- une réponse de resynchronisation en retard n'écrase pas un état plus récent ;
- rechargement à la connexion puis à chaque reconnexion ;
- nouvelle tentative après un échec réseau ;
- état d'une action appliqué immédiatement, event correspondant ignoré ;
- désabonnement au démontage.

### `resources/js/__tests__/PlayerManager.test.ts`

**Rôle.** Bascule d'adaptateur et saut sur erreur.

**Détails.** `FakeAdapter` implémente `PlayerAdapter`, enregistre les appels reçus et peut émettre fin, erreur ou progression à la demande. Cas couverts : adaptateur choisi par source, création à la demande, **pause du précédent avant la bascule**, commandes transmises au seul adaptateur actif ; fin signalée une seule fois, ignorée pour un adaptateur inactif ; erreur du lecteur, échec de chargement, source sans adaptateur → saut ; échec d'un chargement remplacé entre-temps ignoré.

### `resources/js/__tests__/GuestView.test.tsx`

**Rôle.** La télécommande invité.

**Détails.** Minuteries simulées (`vi.useFakeTimers`) : le test avance le temps à la milliseconde.
- **Debounce** : aucune requête à 399 ms, une à 400 ms ; sept frappes rapprochées → une seule requête, avec la saisie finale ; rien en dessous de 3 caractères ; requête précédente annulée.
- **Bouton** : chargement (désactivé) → ajouté ; 409 → « Déjà en file » et resynchronisation ; erreur → « Réessayer », cliquable ; résultat déjà en file → « Ajouté » d'emblée ; 401 → retour à la saisie du pseudo.
- Pseudo, reprise de session, jeton refusé, lecture seule (aucun contrôle hôte, pas de curseur), salon fermé.

Les requêtes de test ciblent les rôles et libellés accessibles (`getByRole('button', { name: … })`) : elles vérifient au passage que chaque état du bouton a un libellé distinct.

### Les tests détectent-ils vraiment les régressions ?

Vérifié par mutation pendant le développement : en supprimant le debounce, en abaissant le minimum à 2 caractères, en appliquant les events sans contrôle de version, ou en retirant la pause lors d'une bascule d'adaptateur, les tests correspondants échouent.

## Point de contrôle E

1. `./vendor/bin/sail artisan test` : tous les tests passent.
2. `./vendor/bin/sail npm test` : tous les tests passent.
3. Cassez volontairement une règle — par exemple, dans `QueueService::add()`, commentez le bloc `if ($alreadyQueued)` — et relancez :

```bash
./vendor/bin/sail artisan test --filter=doublon
```

Le test échoue. Rétablissez le code.

4. Lancer un seul fichier : `./vendor/bin/sail artisan test tests/Feature/SearchServiceTest.php`, ou `./vendor/bin/sail npx vitest run resources/js/__tests__/PlayerManager.test.ts`.
