# Guide du code — Jukebox

Ce guide explique **chaque fichier** du projet : son rôle, ce que fait son code, pourquoi ces choix ont été faits (et quelles alternatives ont été écartées), et comment il s'articule avec les autres. Le code complet est dans le dépôt, commenté en français ; ce guide ne le recopie pas, il l'explique.

| Section | Contenu |
| --- | --- |
| [A — Architecture et données](A-architecture.md) | Arborescence, ADR, installation, migrations, modèles, enums, factories, codes de salon |
| [B — Backend](B-backend.md) | OAuth Spotify, salons, invités, recherche multi-source, file d'attente, routes |
| [C — Temps réel](C-temps-reel.md) | Reverb, canaux, events, endpoint de lecture, resynchronisation |
| [D — Frontend](D-frontend.md) | Types, Echo, hooks, lecteurs Spotify/YouTube, pages, composants |
| [E — Tests](E-tests.md) | Pest (backend) et Vitest (frontend) |
| [F — Clôture](F-cloture.md) | Schéma du flux complet, hypothèses, limites, évolutions |

Chaque section se termine par un **point de contrôle** : ce que vous devez pouvoir vérifier à la main à ce stade.

## Compréhension du besoin et hypothèses (résumé)

1. Deux écrans synchronisés : un hôte qui *joue* la musique, des invités qui *alimentent* une file commune, premier arrivé premier servi.
2. Le serveur est la seule source de vérité ; le lecteur de l'hôte suit l'état du serveur, il ne le définit pas.
3. Pas de lecture « unifiée » : un lecteur par source (SDK Spotify, IFrame YouTube), derrière une interface commune.
4. L'hôte s'authentifie par Spotify uniquement ; les invités n'ont qu'un pseudo et un jeton.
5. Les hypothèses détaillées (alphabet des codes, marché Spotify, jeton invité en clair, etc.) sont listées dans [F — Clôture](F-cloture.md).

## Vérifications réellement effectuées

Ce qui a été exécuté pendant le développement, et ce qui ne l'a pas été :

| Vérification | Résultat |
| --- | --- |
| Tests Pest sur PostgreSQL 16 | 145 tests, tous réussis |
| Tests Vitest | 66 tests, tous réussis |
| `tsc --noEmit` (TypeScript strict) et `vite build` | Sans erreur |
| 30 ajouts **réellement simultanés** contre le serveur | 30 positions distinctes, de 1 à 30 |
| 15 ajouts simultanés du même morceau | 1 accepté (201), 14 refusés (409) |
| Parcours complet dans un navigateur (hôte 1920×1080 + invité mobile, vrai serveur Reverb) | 26 vérifications réussies |
| Lecture **réelle** Spotify et YouTube | **Non testée** : pas de clés d'API ni d'accès à ces services dans l'environnement de développement. Dans le parcours navigateur, l'API IFrame de YouTube était remplacée par un faux lecteur exposant la même interface. Les adaptateurs suivent la documentation officielle des deux SDK, mais leur comportement réel (détection de fin Spotify en particulier) reste à valider avec vos comptes. |
| `docker compose up` (Sail) | **Non exécuté** : Docker n'était pas utilisable dans l'environnement de développement. L'application a été lancée avec PHP 8.3, PostgreSQL 16 et Redis installés directement. Le fichier `docker-compose.yml` est dérivé de celui que génère `sail:install` ; seule sa syntaxe a été validée (`docker compose config`). |
