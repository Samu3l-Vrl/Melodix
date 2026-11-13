# F — Clôture

Le lancement, les variables d'environnement et le parcours de test manuel à deux onglets sont dans le [README](../README.md).

## F.1 Le flux complet : d'un ajout invité à la lecture sur l'hôte

```
 TÉLÉPHONE (invité)                SERVEUR LARAVEL                     REVERB            ÉCRAN HÔTE
 ──────────────────                ───────────────                     ──────            ──────────

 ① tape « daft punk »
    debounce 400 ms, ≥ 3 car.
        │  GET /api/rooms/ABCD/search?q=daft+punk
        │  X-Guest-Token: …
        ├──────────────────────────►  ResolveGuest : jeton ↔ salon
        │                             SearchService :
        │                               cache Redis ? sinon Http::pool
        │                               ├─► Spotify  (jeton d'application)
        │                               └─► YouTube  (search + videos)
        │                             entrelacement + dédoublonnage
        │                             cache : liste (1 h) + chaque morceau
        ◄──────────────────────────┤  { results, sources }

 ② touche « + »
        │  POST /api/rooms/ABCD/queue
        │  { provider, provider_track_id }
        ├──────────────────────────►  TrackResolver : base → cache → source
        │                             QueueService::add
        │                               BEGIN
        │                               SELECT rooms … FOR UPDATE   ◄── verrou
        │                               doublon ? → 409
        │                               position = max + 1
        │                               INSERT queue_items
        │                               version = 7
        │                               COMMIT
        │                             event QueueUpdated(added, v7) ──► room.ABCD ──┬──► applique v7
        ◄──────────────────────────┤  201 + état complet (v7)                      │    (file : +1)
    applique v7                                                                     │
    bouton « Ajouté »        ◄──────────────────────────────────────────────────────┘
    (l'event v7 arrive ensuite : ignoré, version déjà connue)

                                                                                   ③ rien ne joue,
                                                                                      file non vide
                                      POST /host/rooms/ABCD/playback { action: play }
                                   ◄───────────────────────────────────────────────────┤
                                      RoomPolicy::control
                                      QueueService::advance(attendu = null)
                                        verrou, 1er « queued » → « playing »
                                        position_ms = 0, position_updated_at = maintenant
                                        version = 8
                                      event PlaybackStateChanged(v8) ─► room.ABCD ──┬──► applique v8
                                   ├───────────────────────────────────────────────────► état complet (v8)
    applique v8              ◄──────────────────────────────────────────────────────┘
    « en cours » + barre                                                           ④ réconciliation :
    de progression                                                                    PlayerManager.load()
    (position_ms + temps écoulé)                                                      → adaptateur de la source
                                                                                      → le son démarre

                                                                                   ⑤ fin du morceau
                                      POST …/playback { action: ended, item_id }      (onEnded)
                                   ◄───────────────────────────────────────────────────┤
                                      advance(attendu = item_id) → suivant, v9
                                      PlaybackStateChanged(v9) ──────► tous les écrans
```

Ce qu'il faut retenir du schéma :

- **Le téléphone n'envoie que l'identifiant du morceau.** Les métadonnées viennent du serveur.
- **L'action répond avec l'état complet** : l'invité voit son ajout sans attendre l'event. L'event de la même mutation arrive ensuite et est ignoré, car sa version est déjà connue.
- **L'écran hôte déclenche la lecture, le serveur la décide.** L'hôte demande « play » ; le serveur choisit le morceau, écrit l'état, diffuse. Le lecteur ne fait qu'exécuter.
- **Un trou de version** (un téléphone a raté v8) déclenche un `GET /api/rooms/ABCD/state`.

## F.2 Hypothèses prises

Aucune ambiguïté n'était bloquante ; voici les décisions prises sans vous consulter.

| Sujet | Décision |
| --- | --- |
| Statut des salons | Enum `RoomStatus` (`open` / `closed`) ajouté aux trois enums demandés |
| Codes de salon | 4 lettres parmi 24 (A–Z sans I ni O). Les codes des salons fermés restent réservés |
| Nombre de salons par hôte | Illimité ; l'accueil liste les cinq derniers encore ouverts |
| Jeton invité | Stocké en clair (40 caractères), valable pour un seul salon, sans expiration avant la fermeture du salon |
| Pseudos | 1 à 24 caractères, non uniques dans un salon |
| Ajout | Le client envoie (source, identifiant) ; métadonnées résolues côté serveur |
| Payload des events | Deltas (`added` / `removed` / `reordered`), pas la file entière |
| Canal privé de l'hôte | Sert à l'event `GuestJoined` (ajouté : le cahier des charges ne listait que deux events) |
| Démarrage de la lecture | L'écran hôte demande « play » dès que la file n'est plus vide ou qu'une playlist de fond est définie ; le serveur ne démarre jamais seul |
| Playlist de fond | Jouée quand la file est vide, priorité aux invités, reprise au titre suivant, boucle en fin de liste ; un titre joué dans l'heure est sauté. Morceaux copiés en base à l'import (500 au plus) : la lecture ne rappelle jamais la source |
| Retrait d'un morceau | Suppression de la ligne `queue_items` |
| Réordonnancement | Boutons monter / descendre, envoi de l'ordre complet ; refusé (409) si la file a changé entre-temps |
| Ajout par l'hôte | Géré par `QueueService` et `added_by_user_id`, mais sans interface : l'hôte peut ouvrir la télécommande sur son téléphone |
| Marché Spotify | `SPOTIFY_MARKET=FR` par défaut |
| Limitation du `seek` | 1 par seconde et par salon côté serveur (429) ; aucune autre limitation de débit |
| Fichier Docker | Nommé `docker-compose.yml` comme demandé (Sail génère `compose.yaml`, les deux sont acceptés) |
| Sessions, cache | Redis. Migrations `cache` et `jobs` du squelette supprimées |
| Langue | Interface et messages d'erreur en français |
| Fermeture manuelle | Ajout d'un bouton « Fermer le salon » sur l'écran hôte |

## F.3 Limites connues

**Sécurité et maintenance**

- **Laravel 11 ne reçoit plus de correctifs de sécurité.** `composer audit` signale quatre avis sur `laravel/framework`, corrigés uniquement dans les versions 12 et 13. Ils concernent la page de débogage, les URL signées temporaires et la règle de validation `email`, que cette application n'utilise pas en production — mais la montée de version est à planifier.
- **Le canal `room.{code}` et l'endpoint d'état sont publics.** Quiconque connaît ou devine un code peut observer la file et rejoindre le salon. C'est le modèle voulu (le code est l'invitation), mais avec 331 776 codes et aucune limitation de débit, une énumération est possible.
- **Aucune limitation de débit** (hors périmètre) : un invité peut remplir la file ou épuiser le quota YouTube par des recherches variées.
- `allowed_origins` de Reverb vaut `*` : à restreindre à votre domaine en production.

**Lecture**

- **Les lecteurs réels n'ont pas été testés** (voir [le tableau des vérifications](README.md)). La détection de fin de morceau Spotify est une heuristique.
- **YouTube affiche ses publicités** dans son lecteur. Pendant une publicité, la position réelle prend du retard ; l'écran hôte corrige le serveur toutes les 3 secondes, d'où de petits recalages de la barre chez les invités.
- **Commandes natives YouTube** : si l'hôte met en pause depuis le lecteur YouTube plutôt qu'avec le bouton de l'application, le serveur continue de croire que la lecture avance. Utilisez les boutons de l'application.
- **Un seul écran hôte actif.** Deux onglets hôte ouverts jouent tous les deux la musique. Les actions restent cohérentes (grâce à l'idempotence de `advance`), mais le son est doublé.
- **Spotify** : compte Premium obligatoire ; le SDK exige un contexte sécurisé (HTTPS ou `127.0.0.1`) et un navigateur avec DRM (EME), ce qui exclut beaucoup de navigateurs intégrés aux TV : branchez plutôt un PC. En « mode développement », l'application Spotify n'accepte que les comptes déclarés dans son tableau de bord.
- **Onglet hôte en arrière-plan** : les navigateurs ralentissent les minuteries, la détection de fin peut être retardée. Gardez l'écran hôte au premier plan.

**Playlist de fond**

- Les morceaux sont copiés à l'import : une modification ultérieure de la playlist chez Spotify ou YouTube n'est pas répercutée (il faut la choisir à nouveau).
- Spotify refuse aux nouvelles applications l'accès aux playlists éditoriales et générées.
- La lecture des playlists Spotify n'a pas été testée contre la vraie API (seulement avec des réponses simulées), comme le reste de l'intégration Spotify.

**Données et fonctionnement**

- **Quota YouTube** : environ 90 recherches non mises en cache par jour avec le quota gratuit.
- **Dédoublonnage entre sources** : heuristique sur le titre et l'artiste ; elle peut rater un doublon (chaîne « ArtisteVEVO ») ou, plus rarement, fusionner deux versions.
- **Codes jamais libérés** : à terme, purger les salons fermés.
- **Pas de nettoyage** des invités ni des morceaux orphelins.
- **Un seul serveur Reverb** : la mise à l'échelle horizontale (`REVERB_SCALING_ENABLED`) n'est pas configurée.
- **Réordonnancement concurrent** : si un invité ajoute un morceau pendant que l'hôte réordonne, l'action est refusée et l'hôte doit recommencer.
- **Accessibilité** : rôles, libellés et navigation au clavier sont en place, mais aucun audit complet n'a été fait.

## F.4 Prochaines évolutions suggérées

Par ordre de valeur décroissante :

1. **Limitation de débit** : nombre d'ajouts par invité et par minute, plafond de recherches par salon. C'est le premier manque en usage réel.
2. **Montée vers Laravel 12 ou 13**, pour retrouver les correctifs de sécurité.
3. **Vote et veto** : une table `votes`, un event `VotesUpdated`, un tri de la file. Le modèle de versions les accueille sans changement.
4. **Présence** : canal de présence pour afficher les invités connectés, avec un endpoint d'autorisation qui accepte `X-Guest-Token`.
5. **Nouvelles sources** (Deezer, Apple Music) : un cas dans `Provider`, une classe `MusicProvider`, un `PlayerAdapter`, une entrée dans `ProviderRegistry` et dans les fabriques de `HostView`. Rien d'autre ne change.
6. **Glisser-déposer** pour réordonner, et endpoint « déplacer un élément » tolérant aux ajouts concurrents.
7. **Recherche pour l'hôte** depuis l'écran TV.
8. **Historique de la soirée** : les statuts `played` / `skipped` et `played_at` sont déjà enregistrés ; il manque l'écran et un export en playlist Spotify.
9. **Normalisation du volume** entre Spotify et YouTube.
10. **Tests de bout en bout automatisés** (Playwright) avec un faux lecteur, sur le modèle du parcours exécuté à la main pendant le développement.
11. **Production** : HTTPS, Reverb derrière un proxy inverse en `wss`, `php artisan optimize`, Octane ou PHP-FPM à la place du serveur de développement.

## Point de contrôle F

Déroulez le parcours à deux onglets du [README](../README.md#4-parcours-de-test-manuel-2-onglets), étapes 1 à 11. À la fin :

- un morceau ajouté depuis l'onglet invité a démarré seul sur l'onglet hôte ;
- la file était identique sur les deux onglets à chaque instant, avec l'auteur de chaque ajout ;
- un doublon a été refusé avec « Déjà en file » ;
- après une coupure réseau, l'onglet invité s'est rattrapé sans rechargement manuel.
