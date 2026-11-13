# Jukebox

Jukebox collaboratif pour soirées. Un **écran hôte** (TV/PC) joue la musique et affiche la file ; les invités scannent un QR code et ajoutent des morceaux depuis leur téléphone, sans compte. Sources : Spotify et YouTube.

- Écran hôte : `/room/{code}/host` (réservé à l'hôte, connecté avec Spotify)
- Télécommande invité : `/room/{code}/join` (pseudo seul)

Stack : PHP 8.3, Laravel 11, Inertia 2 (React + TypeScript strict), Tailwind, Laravel Reverb + Echo, PostgreSQL 16, Redis, Sail, Pest, Vitest.

Le détail de chaque fichier (rôle, choix, pièges évités) est dans [`docs/`](docs/README.md).

## 1. Lancement avec Sail

Prérequis : Docker (Docker Desktop avec intégration WSL 2 sous Windows). Sous WSL, clonez le projet **dans le système de fichiers Linux** (`~/…`), pas sous `/mnt/c` : c'est beaucoup plus rapide et le rechargement à chaud de Vite fonctionne sans réglage.

```bash
cp .env.example .env
```

Installe les dépendances PHP sans PHP local (Sail lui-même se trouve dans `vendor/`) :

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php83-composer:latest composer install --ignore-platform-reqs
```

Démarre les 5 conteneurs (application, Reverb, planificateur, PostgreSQL, Redis) :

```bash
./vendor/bin/sail up -d
```

```bash
./vendor/bin/sail artisan key:generate
```

```bash
./vendor/bin/sail artisan migrate
```

```bash
./vendor/bin/sail npm install
```

Serveur Vite avec rechargement à chaud (à laisser tourner) :

```bash
./vendor/bin/sail npm run dev
```

L'application répond sur **http://127.0.0.1** (pas `localhost`, voir ci-dessous). Reverb écoute sur le port 8080.

## 2. Variables d'environnement à créer

### Spotify

1. Créez une application sur <https://developer.spotify.com/dashboard>.
2. API utilisées : cochez **Web API** et **Web Playback SDK**.
3. Redirect URI : `http://127.0.0.1/auth/spotify/callback`. Spotify refuse `http://localhost` ; en local seule l'adresse de bouclage `127.0.0.1` est acceptée sans HTTPS. Ouvrez donc toujours l'écran hôte sur `http://127.0.0.1`.
4. Tant que l'application Spotify est en « mode développement », seuls les comptes ajoutés dans *User Management* peuvent se connecter.

```dotenv
SPOTIFY_CLIENT_ID=...
SPOTIFY_CLIENT_SECRET=...
SPOTIFY_REDIRECT_URI="${APP_URL}/auth/spotify/callback"
SPOTIFY_MARKET=FR
```

La lecture Spotify exige un compte **Premium** pour l'hôte. Sans Premium, elle est désactivée et les invités ne voient que des résultats YouTube.

### YouTube

1. Dans <https://console.cloud.google.com>, créez un projet et activez **YouTube Data API v3**.
2. Créez une **clé d'API** (Identifiants → Créer des identifiants → Clé d'API).

```dotenv
YOUTUBE_API_KEY=...
YOUTUBE_DAILY_QUOTA=9500
```

Le quota gratuit est de 10 000 unités par jour et une recherche en coûte 101 : environ 90 recherches *non mises en cache* par jour. L'application met chaque recherche en cache une heure et cesse d'appeler YouTube quand le budget est atteint.

### Accès depuis les téléphones

Le QR code doit pointer vers une adresse que les téléphones peuvent joindre, pas `127.0.0.1` :

```dotenv
JUKEBOX_PUBLIC_URL=http://192.168.1.20
```

Remplacez par l'adresse IP de votre machine sur le Wi-Fi. Sous WSL 2, les ports 80 et 8080 doivent être accessibles depuis le réseau local : activez le mode réseau « mirrored » (`networkingMode=mirrored` dans `.wslconfig`) ou créez des redirections `netsh interface portproxy`, et autorisez ces ports dans le pare-feu Windows.

Pour un test sur téléphone, servez les fichiers compilés plutôt que le serveur Vite (dont l'adresse `localhost:5173` est inconnue du téléphone) : arrêtez `npm run dev`, puis

```bash
./vendor/bin/sail npm run build
```

Après toute modification du `.env` : `./vendor/bin/sail up -d` relit les variables des conteneurs, et relancez `npm run dev` ou `npm run build` (les variables `VITE_*` sont figées à la compilation).

## 3. Tests

```bash
./vendor/bin/sail artisan test
```

```bash
./vendor/bin/sail npm test
```

```bash
./vendor/bin/sail npm run typecheck
```

Les tests PHP utilisent la base PostgreSQL `testing` (créée automatiquement par Sail au premier démarrage du conteneur `pgsql`) et n'appellent jamais Spotify ni YouTube.

## 4. Parcours de test manuel (2 onglets)

**Onglet 1 — l'hôte**, fenêtre normale, sur `http://127.0.0.1` :

1. « Se connecter avec Spotify », acceptez les autorisations.
2. « Créer un salon » : vous arrivez sur `/room/XXXX/host`.
3. « Démarrer la session » (ce clic autorise le navigateur à jouer du son). L'indicateur passe à « Connecté ».

**Onglet 2 — l'invité**, fenêtre de navigation privée (pour ne pas partager la session de l'hôte), outils de développement en mode mobile (F12 → icône téléphone), ou un vrai téléphone via le QR code :

4. Ouvrez `http://127.0.0.1/room/XXXX/join`, saisissez un pseudo. L'onglet hôte affiche « … a rejoint la soirée ».
5. Tapez au moins 3 caractères : après 400 ms d'inactivité les résultats apparaissent, avec un badge Spotify ou YouTube.
6. Touchez « + » : le bouton passe à « Ajouté », et **le morceau démarre tout seul sur l'onglet hôte**.
7. Ajoutez 2 ou 3 autres morceaux : la file se met à jour en direct sur les deux onglets, avec le pseudo de l'auteur.
8. Ouvrez un 3ᵉ onglet invité (autre pseudo) et ajoutez un morceau déjà en file : « Déjà en file ».
9. Sur l'hôte : pause / lecture (la barre de l'invité se fige puis repart), flèches pour réordonner, corbeille pour retirer, « suivant » pour passer.
10. Rechargez l'onglet hôte en pleine lecture, puis « Démarrer la session » : la lecture reprend à la bonne position.
11. Coupez le réseau de l'onglet invité (F12 → Network → Offline), ajoutez un morceau depuis un autre onglet, rétablissez : l'indicateur repasse à « Connecté » et la file se rattrape toute seule.

Sans clés d'API, l'interface reste consultable avec des données de démonstration : `./vendor/bin/sail artisan db:seed` crée un salon avec trois morceaux en file et affiche son code.

## 5. Commandes utiles

| Commande | Effet |
| --- | --- |
| `./vendor/bin/sail logs -f reverb` | Journal du serveur WebSocket |
| `./vendor/bin/sail artisan reverb:start --debug` | Reverb en avant-plan, avec le détail des messages (arrêtez d'abord le service `reverb`) |
| `./vendor/bin/sail artisan rooms:close-inactive` | Ferme les salons inactifs depuis 12 h (exécuté toutes les heures par le service `scheduler`) |
| `./vendor/bin/sail artisan route:list` | Liste des routes |
| `./vendor/bin/sail pint` | Formate le code PHP |

## Limites connues

Voir [`docs/F-cloture.md`](docs/F-cloture.md) : hypothèses prises, limites, pistes d'évolution. Les deux plus importantes : YouTube peut afficher des publicités dans son lecteur (c'est voulu, rien n'est contourné), et Laravel 11 ne reçoit plus de correctifs de sécurité — prévoyez la montée vers Laravel 12 ou 13.
