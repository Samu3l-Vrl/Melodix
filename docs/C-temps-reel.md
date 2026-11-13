# C — Temps réel

## C.1 Reverb et la diffusion

### `config/reverb.php` et `config/broadcasting.php`

**Rôle.** Configuration du serveur WebSocket et du diffuseur. Ce sont les fichiers publiés par Laravel, non modifiés : tout se règle par le `.env`.

**Détails.** Trois groupes de variables, à ne pas confondre :

| Variables | Qui les lit | Valeur avec Sail |
| --- | --- | --- |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | Le processus Reverb : où il écoute | `0.0.0.0:8080` |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Laravel, pour *publier* un event (appel HTTP de serveur à serveur) | `reverb:8080`, `http` |
| `VITE_REVERB_*` | Le navigateur, pour ouvrir le WebSocket | hôte vide = celui de la page, port 8080 |

`REVERB_APP_ID`, `REVERB_APP_KEY` et `REVERB_APP_SECRET` identifient l'application ; la clé est publique (elle part dans le JavaScript), le secret signe les publications et l'autorisation des canaux privés.

**Lancement.** Le service `reverb` du `docker-compose.yml` exécute `php artisan reverb:start --host=0.0.0.0 --port=8080`. Pour déboguer, arrêtez ce service et lancez à la main :

```bash
./vendor/bin/sail artisan reverb:start --debug
```

Chaque connexion, abonnement et message s'affiche alors dans le terminal.

**Limite à connaître.** Reverb refuse par défaut les messages de plus de 10 Ko (`max_message_size`, `max_request_size`). C'est l'une des raisons du choix des *deltas* : un payload ne contient jamais la file entière.

### `app/Services/RoomBroadcaster.php`

**Rôle.** Point de passage unique pour diffuser un event de salon.

**Détails.** La diffusion est « au mieux ». Si Reverb est injoignable, l'action a quand même été validée en base : l'exception est journalisée (`report`) et l'action réussit. Sans ce garde-fou, une panne de Reverb transformerait chaque ajout en erreur 500 alors que le morceau est bien dans la file — et l'invité réessaierait pour obtenir un 409. Les clients rattrapent ensuite par resynchronisation. Un test vérifie ce comportement avec un vrai diffuseur pointé sur un port fermé.

## C.2 Canaux

### `routes/channels.php`

**Rôle.** Autorisation du canal privé de l'hôte.

**Détails.**
- **`room.{code}` — public.** Un canal public n'a pas de callback : tout client connecté à Reverb peut s'y abonner. On n'y diffuse que ce que la TV affiche déjà. Connaître le code suffit, comme pour la page invité.
- **`private-room.{code}.host` — privé.** Il est déclaré sous le nom `room.{code}.host` : Laravel et Echo ajoutent le préfixe `private-`. À l'abonnement, Echo appelle `POST /broadcasting/auth` avec le cookie de session ; le callback vérifie que l'utilisateur est le propriétaire du salon. Un invité n'a pas de session : il est refusé avant même le callback.

Alternative écartée : un canal de présence pour compter les invités. Il aurait exigé d'authentifier les invités auprès de Reverb (endpoint d'autorisation sachant lire `X-Guest-Token`), pour un bénéfice faible en V0.

## C.3 Events

Les trois events implémentent `ShouldBroadcastNow` : ils partent dans la requête même, sans file de jobs. Avec `ShouldBroadcast`, un worker en retard livrerait les events après la réponse HTTP, voire dans le désordre.

### `app/Events/QueueUpdated.php`

**Rôle.** Diffusé sur `room.{code}` à chaque mutation de la file.

**Détails.** Nom court côté client : `.queue.updated` (`broadcastAs`). Le constructeur est privé ; trois fabriques nommées garantissent des payloads cohérents :

| Fabrique | Payload |
| --- | --- |
| `added($room, $item)` | `action: "added"`, `item` : l'élément complet |
| `removed($room, $id)` | `action: "removed"`, `item_id` |
| `reordered($room, $ids)` | `action: "reordered"`, `order` : la liste complète des identifiants |

Tous portent `version` et `server_time`.

### `app/Events/PlaybackStateChanged.php`

**Rôle.** Diffusé sur `room.{code}` à chaque changement de lecture (lecture, pause, déplacement, changement de morceau, arrêt).

**Détails.** Nom court : `.playback.changed`. Le payload contient `playback` (statut, morceau courant complet, `position_ms`, `position_updated_at`). Quand le morceau courant change, le client retire lui-même ce morceau de sa file : **une mutation = un event = une version**, même quand elle touche à la fois la file et la lecture.

### `app/Events/GuestJoined.php`

**Rôle.** Diffusé sur le canal privé de l'hôte quand un invité rejoint le salon.

**Détails.** Hors de l'état versionné, donc sans `version` : le perdre n'a aucune conséquence.

### Le modèle de temps : `server_time` et le point de référence

Le serveur ne diffuse pas « la position est X » toutes les secondes. Il diffuse un **point de référence** : « à l'instant `position_updated_at`, la lecture était à `position_ms` ». Tant que le statut est `playing`, chaque client calcule :

```
position = position_ms + (maintenant_serveur − position_updated_at)
```

Aucun « tick » périodique, donc aucune écriture en base ni aucun event pendant qu'un morceau joue.

Reste un problème : l'horloge d'un téléphone peut être décalée de plusieurs secondes. Chaque payload porte donc `server_time`. Le client estime `décalage = server_time − horloge_locale` et calcule `maintenant_serveur = horloge_locale + décalage`. La latence réseau fausse l'estimation dans un seul sens (le `server_time` reçu est déjà un peu ancien) : le client retient le **plus grand** décalage observé, qui est le plus proche de la réalité.

## C.4 Endpoint de lecture

### `app/Services/PlaybackService.php`

**Rôle.** Mutations de l'état de lecture.

**Détails.** Toutes les méthodes passent par `mutate()` : verrou, modification, horodatage, version, puis diffusion après commit. Sans morceau courant, rien n'est modifié et aucune version n'est créée.
- `play()` : sans morceau courant, délègue à `QueueService::advance($room, null, …)` — si un autre appel a déjà démarré un morceau, rien ne se passe.
- `pause()` : fige la position fournie par l'hôte, sinon la position extrapolée.
- `seek()` : déplace le point de référence sans changer le statut.
- Les positions sont bornées entre 0 et la durée du morceau.

### `app/Http/Controllers/Host/PlaybackController.php` et `app/Http/Requests/PlaybackRequest.php`

**Rôle.** `POST /host/rooms/{code}/playback`.

**Détails.**

| `action` | Champs | Effet |
| --- | --- | --- |
| `play` | `position_ms` facultatif | Lance ou reprend |
| `pause` | `position_ms` facultatif | Met en pause |
| `seek` | `position_ms` obligatoire | Déplace la position |
| `ended` | `item_id` obligatoire | Fin naturelle : `advance()` avec le statut `played` |

`PlaybackRequest::authorize()` applique `RoomPolicy::control`.

**Limitation de `seek` à une par seconde et par salon** (`RateLimiter`) : glisser le curseur ou corriger une dérive ne doit pas produire une rafale d'écritures et d'events. Au-delà : 429 avec le code `seek_throttled`, que le client ignore silencieusement. `play` et `pause` ne sont pas limités.

Pourquoi `ended` est-il ici et le skip dans `/queue/advance` ? Les deux appellent `advance()`, mais avec un statut final différent (`played` / `skipped`), ce qui garde un historique exact de la soirée.

## C.5 Resynchronisation

### `app/Http/Controllers/Api/RoomStateController.php`

**Rôle.** `GET /api/rooms/{code}/state` : état complet et `version`.

**Détails.** Public, comme le canal : il n'expose rien de plus que ce que l'écran de la soirée affiche.

### Le contrat, côté client

Implémenté dans `resources/js/lib/roomReducer.ts` et `resources/js/hooks/useRoomState.ts` :

| Version reçue | Décision | Raison |
| --- | --- | --- |
| ≤ version locale | **Ignorer** | Doublon, ou event arrivé après la réponse HTTP de la même action |
| = version locale + 1 | **Appliquer** le delta | C'est l'event suivant attendu |
| > version locale + 1 | **Recharger** l'état complet | Au moins un delta manque : appliquer celui-ci donnerait une file fausse |

Un rechargement a aussi lieu à chaque (re)connexion du WebSocket et quand l'onglet repasse au premier plan (téléphone sorti de veille).

Deux garanties côté serveur rendent ce contrat exact : `version` augmente de 1 par mutation (verrou de ligne), et chaque mutation émet exactement un event (vérifié par un test qui enchaîne onze mutations et attend les versions 1 à 11).

## Point de contrôle C

1. Reverb en mode verbeux :

```bash
./vendor/bin/sail stop reverb
```

```bash
./vendor/bin/sail artisan reverb:start --debug
```

2. Ouvrez l'écran hôte et une page invité : le terminal affiche deux connexions et les abonnements à `room.XXXX` (et `private-room.XXXX.host` pour l'hôte).
3. Ajoutez un morceau avec la commande `curl` du point de contrôle B : un message `queue.updated` apparaît dans le terminal, avec `version` et `server_time`, et la file se met à jour sur les deux pages sans rechargement.
4. Lisez l'état et comparez la version à celle du dernier event :

```bash
curl -s http://127.0.0.1/api/rooms/XXXX/state
```

5. Arrêtez Reverb (Ctrl+C), ajoutez un morceau avec `curl` : la réponse reste **201**. Les pages passent à « Reconnexion… » puis « Hors ligne ». Relancez Reverb : elles repassent à « Connecté » et affichent le morceau manqué.
6. Dans la console du navigateur d'une page invité, l'onglet Réseau montre que l'abonnement au canal privé n'est jamais tenté ; avec un autre compte hôte, `POST /broadcasting/auth` répond 403.

N'oubliez pas de redémarrer le service ensuite : `./vendor/bin/sail up -d reverb`.
