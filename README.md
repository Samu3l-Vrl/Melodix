# Jukebox

Jukebox collaboratif pour soirées. Un **écran hôte** (un PC branché à la TV) joue la musique et affiche la file d'attente ; les invités scannent un QR code et ajoutent des morceaux depuis leur téléphone, sans créer de compte. Sources : Spotify et YouTube.

- Écran hôte : `/room/{code}/host` (réservé à l'hôte, connecté avec Spotify)
- Télécommande invité : `/room/{code}/join` (un pseudo suffit)

Stack : PHP 8.3, Laravel 11, Inertia 2 (React + TypeScript strict), Tailwind, Laravel Reverb + Echo, PostgreSQL 16, Redis, Laravel Sail (Docker), Pest, Vitest.

Ce guide vous emmène de zéro à une soirée qui fonctionne. Comptez **environ 45 minutes** la première fois, dont une bonne part d'attente (téléchargements Docker). Le fonctionnement interne du code est expliqué dans [`docs/`](docs/README.md).

---

## Sommaire

0. [Comment ça marche physiquement](#0-comment-ça-marche-physiquement)
1. [Prérequis](#1-prérequis)
2. [Récupérer le code](#2-récupérer-le-code)
3. [Créer l'application Spotify](#3-créer-lapplication-spotify)
4. [Créer la clé YouTube](#4-créer-la-clé-youtube)
5. [Configurer le fichier `.env`](#5-configurer-le-fichier-env)
6. [Installer et démarrer](#6-installer-et-démarrer)
7. [Premier essai sur votre PC](#7-premier-essai-sur-votre-pc)
   - [Musique de fond (playlist)](#7-bis-musique-de-fond-playlist)
   - [Transitions entre les morceaux](#transitions-entre-les-morceaux)
8. [Ouvrir l'accès aux téléphones](#8-ouvrir-laccès-aux-téléphones)
9. [Le jour de la soirée](#9-le-jour-de-la-soirée)
10. [Commandes du quotidien](#10-commandes-du-quotidien)
11. [Dépannage](#11-dépannage)
12. [Lancer les tests](#12-lancer-les-tests)
13. [Limites à connaître](#13-limites-à-connaître)

---

## 0. Comment ça marche physiquement

```
        Wi-Fi de la maison
   ┌───────────────────────────────────────────────┐
   │                                               │
   │   PC (Docker + l'application)  ──HDMI──►  TV  │
   │   navigateur ouvert sur                       │
   │   http://127.0.0.1:8000/room/ABCD/host        │
   │        ▲            ▲                         │
   │        │ page web   │ temps réel (port 8080)  │
   │        │            │                         │
   │   📱 téléphones des invités                    │
   │   http://192.168.1.20:8000/room/ABCD/join     │
   └───────────────────────────────────────────────┘
```

Trois points à comprendre avant de commencer :

1. **Le PC qui fait tourner l'application est aussi l'écran hôte.** Spotify n'autorise la connexion sans HTTPS que depuis l'adresse `http://127.0.0.1:8000`, c'est-à-dire depuis la machine elle-même. Branchez ce PC sur la TV en HDMI (ou affichez-le sur un vidéoprojecteur). Le navigateur intégré d'une TV ne convient pas : il ne sait généralement pas lire Spotify (protection DRM).
2. **C'est le navigateur de ce PC qui joue la musique.** Les haut-parleurs sont ceux du PC ou de la TV.
3. **Les téléphones doivent être sur le même Wi-Fi** et pouvoir joindre le PC (étape 8).

---

## 1. Prérequis

### Matériel et comptes

| Élément | Pourquoi |
| --- | --- |
| Un PC Windows 10/11, macOS ou Linux, 8 Go de RAM conseillés | Il fait tourner Docker et joue la musique |
| Google Chrome, Edge ou Firefox récent sur ce PC | Le lecteur Spotify a besoin d'un navigateur avec DRM activé |
| Un compte **Spotify Premium** | Obligatoire pour lire du Spotify dans le navigateur. Sans Premium, l'application fonctionne en **YouTube seulement** |
| Un compte Google | Pour la clé d'API YouTube (gratuite) |
| Un compte Spotify (même gratuit) pour le tableau de bord développeur | Pour créer l'application Spotify |

### Logiciels

**Windows** — tout se passe dans WSL 2 (un Linux intégré à Windows) :

1. Ouvrez PowerShell **en administrateur** et lancez :

   ```powershell
   wsl --install -d Ubuntu
   ```

   Redémarrez si demandé, puis ouvrez « Ubuntu » depuis le menu Démarrer et choisissez un nom d'utilisateur et un mot de passe Linux.
2. Installez [Docker Desktop](https://www.docker.com/products/docker-desktop/). Dans *Settings → General*, cochez **Use the WSL 2 based engine** ; dans *Settings → Resources → WSL integration*, activez **Ubuntu**.
3. Dans le terminal Ubuntu, vérifiez :

   ```bash
   docker run --rm hello-world
   ```

   Vous devez lire « Hello from Docker! ». Si vous obtenez `permission denied … docker.sock`, ajoutez votre utilisateur au groupe `docker` :

   ```bash
   sudo groupadd -f docker && sudo usermod -aG docker $USER
   ```

   puis lancez `wsl --shutdown` dans PowerShell, rouvrez Ubuntu et refaites le test.

> **Important sous Windows :** toutes les commandes de ce guide se tapent dans le terminal **Ubuntu**, pas dans PowerShell (sauf mention contraire), et le projet doit être rangé dans votre dossier Linux (`~/…`), **pas** dans `/mnt/c/…`. Sous `/mnt/c`, tout est 5 à 10 fois plus lent et le rechargement automatique du code ne fonctionne pas.

**macOS** : installez [Docker Desktop](https://www.docker.com/products/docker-desktop/) et lancez-le. Utilisez l'application Terminal.

**Linux** : installez Docker Engine et le plugin Compose ([documentation officielle](https://docs.docker.com/engine/install/)), et ajoutez votre utilisateur au groupe `docker`.

Dans tous les cas, `git` est nécessaire :

```bash
git --version
```

S'il manque (Ubuntu) :

```bash
sudo apt update && sudo apt install -y git
```

---

## 2. Récupérer le code

```bash
cd ~
```

```bash
git clone -b claude/gifted-archimedes-nyilpp https://github.com/Samu3l-Vrl/Melodix.git jukebox
```

```bash
cd jukebox
```

Le code se trouve pour l'instant sur la branche `claude/gifted-archimedes-nyilpp`. Une fois cette branche fusionnée dans la branche principale, retirez `-b claude/gifted-archimedes-nyilpp` de la commande. Si le dépôt est privé, Git vous demandera de vous authentifier ([jeton d'accès GitHub](https://docs.github.com/fr/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens) à la place du mot de passe).

Créez votre fichier de configuration à partir du modèle :

```bash
cp .env.example .env
```

Vous le remplirez à l'étape 5. Le fichier `.env` contient vos secrets : il est ignoré par Git, ne le partagez pas.

---

## 3. Créer l'application Spotify

Cette application sert à deux choses : connecter l'hôte avec son compte Spotify, et faire les recherches Spotify des invités.

1. Allez sur <https://developer.spotify.com/dashboard> et connectez-vous. Acceptez les conditions développeur si c'est votre première visite.
2. Cliquez sur **Create app** et remplissez :

   | Champ | Valeur |
   | --- | --- |
   | App name | `Jukebox` (libre) |
   | App description | `Jukebox de soirée` (libre) |
   | Website | laisser vide |
   | **Redirect URIs** | `http://127.0.0.1:8000/auth/spotify/callback` puis cliquez sur **Add** |
   | **Which API/SDKs are you planning to use?** | cochez **Web API** et **Web Playback SDK** |

   Acceptez les conditions et cliquez sur **Save**.

   ⚠️ L'adresse de redirection doit être recopiée **exactement** : `http`, `127.0.0.1` (pas `localhost`, refusé par Spotify), le port `:8000`, pas de `/` final. Le tableau de bord peut afficher l'avertissement « This redirect URI is not secure » pour une adresse en `http` : pour `127.0.0.1` avec un port, Spotify l'accepte quand même. Vérifiez après **Save** que l'adresse figure bien dans la liste. Si vous changez de port (§11), déclarez la nouvelle adresse ici aussi.
3. Ouvrez l'application, puis **Settings**. Notez le **Client ID**, cliquez sur **View client secret** et notez le **Client secret**.
4. Onglet **User Management** : ajoutez le nom et l'adresse e-mail du **compte Spotify de l'hôte** (celui qui sera branché sur la TV, s'il est différent du vôtre). Une application nouvellement créée est en « mode développement » : seuls les comptes listés ici peuvent s'y connecter, dans une limite fixée par Spotify (indiquée sur cette page). Les invités, eux, n'ont pas besoin d'y figurer : ils ne se connectent jamais à Spotify.

---

## 4. Créer la clé YouTube

1. Allez sur <https://console.cloud.google.com> et connectez-vous avec votre compte Google.
2. En haut à gauche, cliquez sur le sélecteur de projet → **Nouveau projet** → nom `Jukebox` → **Créer**. Vérifiez que ce projet est bien sélectionné.
3. Menu ☰ → **API et services** → **Bibliothèque**. Cherchez **YouTube Data API v3**, ouvrez-la et cliquez sur **Activer**.
4. Menu ☰ → **API et services** → **Identifiants** → **Créer des identifiants** → **Clé API**. Copiez la clé affichée.
5. Recommandé : cliquez sur **Modifier la clé API**, puis dans **Restrictions relatives aux API**, choisissez **Restreindre la clé** et cochez uniquement **YouTube Data API v3**. Enregistrez. Si la clé fuite, elle ne pourra servir à rien d'autre.
   Ne mettez pas de restriction « Sites web (référents HTTP) » : la clé est utilisée par le serveur, pas par le navigateur, et serait alors refusée.

Aucune carte bancaire n'est nécessaire. Le quota gratuit est de 10 000 unités par jour ; une recherche en coûte 101, soit **environ 90 recherches différentes par jour**. Chaque recherche est gardée en cache une heure (une même recherche répétée ne coûte rien), et l'application s'arrête d'elle-même avant d'atteindre la limite. Le quota se remet à zéro chaque jour à minuit, heure du Pacifique (9 h du matin à Paris).

---

## 5. Configurer le fichier `.env`

Ouvrez le fichier `.env` créé à l'étape 2. Sous Windows, le plus simple est :

```bash
code .env
```

(VS Code avec l'extension WSL), ou `nano .env` dans le terminal (Ctrl+O pour enregistrer, Ctrl+X pour quitter).

Seules ces lignes sont à modifier pour l'instant :

```dotenv
SPOTIFY_CLIENT_ID=collez-ici-le-client-id
SPOTIFY_CLIENT_SECRET=collez-ici-le-client-secret
YOUTUBE_API_KEY=collez-ici-la-cle-youtube
```

Tout le reste est déjà réglé pour Sail. Pour information, les autres réglages utiles :

| Variable | Valeur par défaut | Quand la changer |
| --- | --- | --- |
| `APP_URL` | `http://127.0.0.1:8000` | Si vous changez de port (voir « Le port 8000 est déjà utilisé » au §11) |
| `APP_PORT` | `8000` | Idem |
| `JUKEBOX_PUBLIC_URL` | vide | À l'étape 8, pour les téléphones |
| `SPOTIFY_MARKET` | `FR` | Si vous êtes dans un autre pays (code à 2 lettres : `BE`, `CH`, `CA`…) |
| `YOUTUBE_DAILY_QUOTA` | `9500` | Si Google vous a accordé un quota plus élevé |
| `JUKEBOX_INACTIVITY_HOURS` | `12` | Délai avant fermeture automatique d'un salon inutilisé |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET` | valeurs d'exemple | Remplacez-les par des chaînes aléatoires si l'application est exposée au-delà de votre réseau local |

Ne touchez pas à `DB_HOST=pgsql`, `REDIS_HOST=redis` ni `REVERB_HOST=reverb` : ce sont les noms des conteneurs Docker.

---

## 6. Installer et démarrer

Toutes les commandes se lancent depuis le dossier `jukebox`.

### 6.1 Installer les dépendances PHP (une seule fois)

Sail lui-même fait partie des dépendances PHP : il faut donc les installer une première fois avec un conteneur temporaire, sans avoir PHP sur votre machine.

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php83-composer:latest composer install --ignore-platform-reqs
```

Durée : 1 à 3 minutes. À la fin, un dossier `vendor/` existe.

### 6.2 Créer un raccourci `sail`

Pour taper `sail` au lieu de `./vendor/bin/sail` :

```bash
echo "alias sail='sh \$([ -f sail ] && echo sail || echo vendor/bin/sail)'" >> ~/.bashrc && source ~/.bashrc
```

(Sur macOS avec zsh, remplacez `~/.bashrc` par `~/.zshrc`.) La suite du guide utilise `sail`.

### 6.3 Démarrer les conteneurs

```bash
sail up -d
```

La **première fois**, Docker construit l'image PHP et télécharge PostgreSQL et Redis : comptez 5 à 15 minutes. Les fois suivantes, quelques secondes.

Vérifiez que les cinq services tournent :

```bash
sail ps
```

Vous devez voir `laravel.test`, `reverb`, `scheduler`, `pgsql` et `redis` à l'état `running` (ou `Up`).

| Service | Rôle |
| --- | --- |
| `laravel.test` | L'application web (port 8000) et le serveur Vite (port 5173) |
| `reverb` | Le serveur temps réel (WebSocket, port 8080) |
| `scheduler` | Ferme les salons inutilisés toutes les heures |
| `pgsql` | La base de données |
| `redis` | Cache des recherches, sessions, compteur du quota YouTube |

### 6.4 Préparer l'application (une seule fois)

Génère la clé de chiffrement de l'application (elle protège notamment les jetons Spotify stockés en base) :

```bash
sail artisan key:generate
```

Crée les tables :

```bash
sail artisan migrate
```

Installe les dépendances JavaScript :

```bash
sail npm install
```

Redémarre Reverb et le planificateur pour qu'ils lisent la nouvelle clé :

```bash
sail restart reverb scheduler
```

### 6.5 Compiler l'interface

Deux façons de faire, selon votre usage :

- **Pour modifier le code** (rechargement automatique à chaque modification) — à laisser tourner dans un terminal dédié :

  ```bash
  sail npm run dev
  ```

  Dans ce mode, **seul le PC** peut afficher les pages : les téléphones ne peuvent pas joindre le serveur de développement.

- **Pour une soirée** (fichiers compilés, accessibles aux téléphones) :

  ```bash
  sail npm run build
  ```

  À relancer après chaque modification du code ou des variables `VITE_*`.

Pour le premier essai sur le PC, l'une ou l'autre convient. Si vous hésitez, prenez `sail npm run build`.

### Ce que vous devez voir

Ouvrez **http://127.0.0.1:8000** dans le navigateur du PC (et pas `localhost`). La page d'accueil « Jukebox » s'affiche, avec « Rejoindre un salon » et « Se connecter avec Spotify ».

---

## 7. Premier essai sur votre PC

Faites ce test sur le PC seul avant d'impliquer des téléphones.

**Onglet 1 — l'hôte**, fenêtre normale, sur `http://127.0.0.1:8000` :

1. Cliquez sur **Se connecter avec Spotify**, connectez-vous et acceptez les autorisations. Vous revenez sur l'accueil avec votre nom.
   - Un message jaune « compte non Premium » signifie que seul YouTube sera disponible.
2. Cliquez sur **Créer un salon**. Vous arrivez sur l'écran hôte avec un code à 4 lettres (par exemple `KZPW`).
3. Cliquez sur **Démarrer la session**. Ce clic est indispensable : les navigateurs interdisent de jouer du son sans action de l'utilisateur. En haut à droite, l'indicateur passe à **Connecté**.

**Onglet 2 — un invité**, dans une **fenêtre de navigation privée** (Ctrl+Maj+N) pour ne pas partager la connexion de l'hôte :

4. Ouvrez `http://127.0.0.1:8000/room/KZPW/join` (avec votre code). Pour simuler un téléphone : F12, puis l'icône téléphone/tablette.
5. Saisissez un pseudo. Sur l'onglet hôte apparaît « … a rejoint la soirée ».
6. Tapez une recherche d'au moins 3 caractères. Après une courte pause, des résultats apparaissent avec un badge **Spotify** ou **YouTube**.
7. Touchez **+** sur un résultat. Le bouton passe à **Ajouté** et **la musique démarre toute seule sur l'onglet hôte**.
8. Ajoutez deux ou trois autres morceaux : ils apparaissent instantanément dans la file des deux onglets, avec le pseudo de l'auteur.

Essayez ensuite, sur l'écran hôte : pause / lecture, cliquer dans la barre de progression, les flèches pour réordonner, la corbeille pour retirer, le bouton « suivant ».

Si une étape échoue, voyez le [dépannage](#11-dépannage).

Sans clés d'API, vous pouvez quand même découvrir l'interface invité avec des données de démonstration : `sail artisan db:seed` crée un salon avec trois morceaux et affiche son code (ouvrez alors `http://127.0.0.1:8000/room/CODE/join`).

---

## 7 bis. Musique de fond (playlist)

Pour que la musique ne s'arrête jamais, choisissez une playlist en créant le salon : elle joue quand la file des invités est vide. Les invités, eux, ne font qu'ajouter des morceaux, qui **passent toujours avant** le titre suivant de la playlist. Une fois la file vidée, la playlist reprend là où elle s'était arrêtée, et recommence au début quand elle est finie.

**À la création du salon :** sur l'accueil, cochez **Musique de fond**, puis :
- choisissez une de **vos playlists Spotify** dans la liste (compte Premium nécessaire), ou
- collez le **lien de partage** d'une playlist Spotify ou YouTube :
  - Spotify : application → playlist → ⋯ → Partager → Copier le lien (`https://open.spotify.com/playlist/…`) ;
  - YouTube : page de la playlist → Partager → Copier (`https://www.youtube.com/playlist?list=…`).

Cochez **Lecture aléatoire** pour mélanger l'ordre. Si la playlist est inutilisable, un message l'explique et aucun salon n'est créé.

**Pendant la soirée**, sur l'écran hôte, le bandeau **Musique de fond** au-dessus de la file permet de **changer** de playlist ou de la **retirer** (la musique s'arrêtera alors à la fin de la file).

Bon à savoir :
- Si vous vous étiez connecté avec Spotify **avant** cette fonctionnalité, déconnectez-vous puis reconnectez-vous une fois : Spotify doit vous redemander l'autorisation de lire vos playlists. Sans cela, vos playlists privées n'apparaissent pas.
- Spotify ne donne pas accès à ses playlists **éditoriales ou générées** (« Daily Mix », « Top 50 France », « Discover Weekly »…) aux nouvelles applications. Utilisez une playlist créée par vous ou par un autre utilisateur ; vous pouvez aussi copier les titres d'une playlist éditoriale dans une playlist à vous.
- Les « mix » générés par YouTube (`list=RD…`) ne sont pas lisibles non plus ; les playlists publiques et non répertoriées le sont.
- Une playlist YouTube coûte très peu de quota : environ 2 unités par tranche de 50 titres, contre 101 pour une recherche.
- Au plus 500 titres sont importés (réglable avec `JUKEBOX_PLAYLIST_MAX_TRACKS`). Les vidéos supprimées, privées ou non intégrables sont ignorées.
- Un titre joué depuis moins d'une heure (par exemple parce qu'un invité l'a demandé) est sauté par la playlist.
- Les titres Spotify d'une playlist sont sautés si l'hôte n'est pas Premium.

### Transitions entre les morceaux

Sur l'écran hôte, le réglage **Transitions** (à côté des boutons de lecture) adoucit le passage d'un morceau à l'autre : le morceau qui se termine baisse progressivement pendant ses dernières secondes, le suivant démarre aussitôt et monte en volume. Choix : **Aucune**, **3 s**, **6 s** (par défaut) ou **10 s**. Le réglage est enregistré pour le salon.

Ce n'est pas un fondu *enchaîné* (les deux morceaux ne se superposent pas) : Spotify ne joue qu'un morceau à la fois par compte, et YouTube impose un lecteur visible. Le fondu supprime en revanche le silence de chargement entre deux morceaux. Il s'interrompt pendant une pause, et ne démarre pas tant qu'une publicité YouTube est en cours.

---

## 8. Ouvrir l'accès aux téléphones

### 8.1 Trouver l'adresse IP du PC sur le Wi-Fi

- **Windows** : dans PowerShell, tapez `ipconfig` et relevez l'« Adresse IPv4 » de la carte « Wi-Fi » (ou « Ethernet » si le PC est câblé), par exemple `192.168.1.20`. Ne prenez **pas** l'adresse de la carte « vEthernet (WSL) ».
- **macOS** : Réglages Système → Wi-Fi → Détails → Adresse IP.
- **Linux** : `hostname -I` (la première adresse).

### 8.2 L'indiquer à l'application

Dans `.env` :

```dotenv
JUKEBOX_PUBLIC_URL=http://192.168.1.20:8000
```

Le QR code affiché sur l'écran hôte pointera désormais vers cette adresse. Rechargez l'écran hôte pour le voir changer (aucun redémarrage nécessaire).

Conseil : l'adresse IP peut changer d'un jour à l'autre. Dans l'interface de votre box, réservez une adresse fixe pour ce PC (« bail DHCP statique » ou « IP fixe »).

### 8.3 Autoriser les connexions entrantes (Windows)

Le pare-feu Windows bloque par défaut les connexions venant des téléphones. Dans PowerShell **en administrateur** :

```powershell
New-NetFirewallRule -DisplayName "Jukebox" -Direction Inbound -Protocol TCP -LocalPort 8000,8080 -Action Allow -Profile Private
```

Vérifiez aussi que votre réseau Wi-Fi est en profil **Privé** : Paramètres → Réseau et Internet → Wi-Fi → (votre réseau) → Type de profil réseau → **Réseau privé**.

Avec Docker Desktop, les ports publiés par les conteneurs sont accessibles depuis le réseau local une fois le pare-feu ouvert. Si vous utilisez Docker installé directement dans WSL (sans Docker Desktop), il faut en plus activer le réseau « mirrored » : créez le fichier `C:\Users\<vous>\.wslconfig` contenant

```ini
[wsl2]
networkingMode=mirrored
```

puis, dans PowerShell **en administrateur**, autorisez les connexions entrantes vers WSL (en mode mirrored, un pare-feu propre à WSL les bloque par défaut) :

```powershell
New-NetFirewallHyperVRule -Name "Jukebox" -DisplayName "Jukebox" -Direction Inbound -VMCreatorId '{40E0AC32-46A5-438A-A0B2-2B479E8F2E90}' -Protocol TCP -LocalPorts 8000,8080
```

Enfin, lancez `wsl --shutdown`, rouvrez Ubuntu et relancez `sail up -d` (Windows 11 22H2 ou plus récent).

Pour savoir dans quel cas vous êtes, tapez `docker info --format '{{.OperatingSystem}}'` dans Ubuntu : « Docker Desktop » ou le nom de votre distribution (Docker installé dans WSL). Autre test : ouvrez `http://VOTRE_IP:8000/up` dans le navigateur **du PC**. Si cette page ne s'affiche pas alors que `http://127.0.0.1:8000/up` fonctionne, le port n'est pas exposé au réseau : c'est le cas « Docker dans WSL », à régler comme ci-dessus.

macOS et Linux : autorisez les ports 8000 et 8080 si un pare-feu est actif.

### 8.4 Compiler l'interface

Les téléphones ne peuvent pas utiliser le serveur de développement : arrêtez `sail npm run dev` s'il tourne (Ctrl+C), puis :

```bash
sail npm run build
```

### 8.5 Tester depuis un téléphone

Le téléphone doit être connecté **au même Wi-Fi** que le PC.

1. Dans le navigateur du téléphone, ouvrez `http://192.168.1.20:8000/up` (votre IP). Une page « Application up » doit s'afficher. Sinon : pare-feu, mauvaise IP, ou réseau différent (voir §11).
2. Scannez le QR code de l'écran hôte avec l'appareil photo. La page invité s'ouvre.
3. L'indicateur Wi-Fi en haut à droite de la page invité doit être **vert**. S'il reste orange ou rouge, le port 8080 (temps réel) est bloqué.
4. Ajoutez un morceau : il doit apparaître sur la TV.

---

## 9. Le jour de la soirée

**Avant l'arrivée des invités :**

- [ ] Le PC est branché sur le secteur (pas sur batterie) et sur la TV.
- [ ] La mise en veille du PC est désactivée (Windows : Paramètres → Système → Alimentation → Écran et veille → « Jamais » pour la veille sur secteur).
- [ ] Docker Desktop est lancé, puis dans le terminal : `cd ~/jukebox && sail up -d`.
- [ ] `sail npm run build` a été lancé depuis la dernière modification.
- [ ] L'adresse IP du PC n'a pas changé (sinon mettez à jour `JUKEBOX_PUBLIC_URL`).
- [ ] Les invités se connecteront au **Wi-Fi principal**, pas à un « Wi-Fi invités » : ces réseaux isolent souvent les appareils les uns des autres, et les téléphones ne verraient pas le PC.
- [ ] Ouvrez `http://127.0.0.1:8000`, reprenez votre salon ou créez-en un, cliquez sur **Démarrer la session**.
- [ ] Mettez le navigateur en plein écran (F11) et laissez **cet onglet au premier plan** : en arrière-plan, le navigateur ralentit l'onglet et l'enchaînement des morceaux peut prendre du retard.
- [ ] Testez avec votre propre téléphone : scan du QR code, ajout d'un morceau.

**Pendant la soirée :**

- Si la page hôte est rechargée, cliquez de nouveau sur **Démarrer la session** : la lecture reprend où elle en était.
- Pilotez la lecture avec les boutons de l'application (pause, suivant), pas avec les commandes du lecteur YouTube : sinon l'application ne sait pas que la lecture est en pause.
- Un morceau illisible (vidéo dont l'intégration est interdite, par exemple) est sauté automatiquement.
- YouTube peut afficher des publicités dans son lecteur : c'est normal, l'application ne les contourne pas.

**Après la soirée :** « Fermer le salon » en haut à droite. Sinon il se ferme tout seul après 12 h sans activité. Puis `sail stop`.

---

## 10. Commandes du quotidien

| Commande | Effet |
| --- | --- |
| `sail up -d` | Démarre tout en arrière-plan |
| `sail stop` | Arrête tout (les données sont conservées) |
| `sail ps` | État des services |
| `sail restart reverb scheduler` | À faire après **toute modification du `.env`** (avec `sail npm run build` si une variable `VITE_*` a changé) |
| `sail npm run build` | Recompile l'interface |
| `sail npm run dev` | Mode développement avec rechargement automatique (PC uniquement) |
| `sail logs -f laravel.test` | Journal de l'application (Ctrl+C pour quitter) |
| `sail logs -f reverb` | Journal du serveur temps réel |
| `tail -f storage/logs/laravel.log` | Erreurs détaillées de l'application |
| `sail artisan rooms:close-inactive` | Ferme tout de suite les salons inutilisés depuis 12 h |
| `sail artisan migrate` | Applique les nouvelles migrations après une mise à jour du code |
| `sail down -v` | ⚠️ Supprime les conteneurs **et la base de données** (repartir de zéro) |

**Mettre à jour le code :**

```bash
git pull
```

```bash
sail composer install
```

```bash
sail npm install
```

```bash
sail artisan migrate
```

```bash
sail npm run build
```

```bash
sail restart reverb scheduler
```

---

## 11. Dépannage

Premier réflexe dans tous les cas : regardez les journaux avec `tail -n 50 storage/logs/laravel.log` et `sail logs reverb`.

### Installation et démarrage

| Symptôme | Cause probable | Solution |
| --- | --- | --- |
| `permission denied while trying to connect to the docker API at unix:///var/run/docker.sock` | Votre utilisateur n'est pas dans le groupe `docker` | `sudo groupadd -f docker`, puis `sudo usermod -aG docker $USER`, puis `wsl --shutdown` dans PowerShell et rouvrez Ubuntu (ou `newgrp docker` pour le terminal courant). N'utilisez pas `sudo docker` : les fichiers créés appartiendraient à root |
| `docker: command not found` ou `Cannot connect to the Docker daemon` | Docker Desktop n'est pas lancé, ou l'intégration WSL n'est pas activée | Lancez Docker Desktop ; vérifiez *Settings → Resources → WSL integration* |
| `Bind for 0.0.0.0:8000 failed: port is already allocated` | Un autre logiciel utilise le port 8000 | Voir « Le port 8000 est déjà utilisé » ci-dessous |
| `http://127.0.0.1:8000` affiche « Index of / », une liste de fichiers ou un autre site | Un programme Windows écoute déjà ce port sur `127.0.0.1` et passe avant Docker (XAMPP, WAMP, IIS, Live Server…) | Changez de port (ci-dessous). Pour identifier le programme, dans PowerShell : `Get-Process -Id (Get-NetTCPConnection -LocalPort 8000 -State Listen).OwningProcess` |
| `Bind for 0.0.0.0:5432 failed` (ou 6379) | Un PostgreSQL (ou Redis) est déjà installé sur la machine | Dans `.env` : `FORWARD_DB_PORT=5433` (ou `FORWARD_REDIS_PORT=6380`), puis `sail up -d` |
| `sail: command not found` | L'alias n'est pas chargé | `source ~/.bashrc`, ou utilisez `./vendor/bin/sail` |
| `vendor/bin/sail: No such file or directory` | L'étape 6.1 n'a pas été faite | Lancez la commande `docker run … composer install` du §6.1 |
| Le service `reverb` ou `scheduler` redémarre en boucle | Dépendances absentes ou clé d'application manquante | Vérifiez §6.1 et `sail artisan key:generate`, puis `sail restart reverb scheduler` |
| `Permission denied` sur `storage/` ou `bootstrap/cache/` | Fichiers créés par un autre utilisateur | `sudo chown -R "$USER": storage bootstrap/cache` |
| Erreur `Vite manifest not found` | L'interface n'a pas été compilée | `sail npm run build` |
| Tout est très lent (Windows) | Le projet est sous `/mnt/c/…` | Déplacez-le dans `~/` (voir §1) |

**Le port 8000 est déjà utilisé.** Choisissez un autre port, par exemple 8001 (pas 8080, pris par Reverb). Dans `.env` :

```dotenv
APP_PORT=8001
APP_URL=http://127.0.0.1:8001
```

Dans le tableau de bord Spotify, ajoutez la Redirect URI `http://127.0.0.1:8001/auth/spotify/callback`. Pour les téléphones, `JUKEBOX_PUBLIC_URL=http://192.168.1.20:8001` et ouvrez le port 8001 dans le pare-feu au lieu du 8000. Puis `sail up -d`, `sail restart reverb scheduler`, et ouvrez `http://127.0.0.1:8001`.

### Connexion Spotify

| Symptôme | Cause probable | Solution |
| --- | --- | --- |
| Page Spotify « INVALID_CLIENT: Invalid redirect URI » | L'adresse de redirection ne correspond pas exactement | Comparez la Redirect URI du tableau de bord avec `APP_URL` + `/auth/spotify/callback` (même port, `127.0.0.1`, pas de `/` final) |
| Page Spotify « INVALID_CLIENT: Invalid client » | Client ID erroné | Recopiez `SPOTIFY_CLIENT_ID` |
| Retour à l'accueil avec « La connexion à Spotify a échoué » | Client secret erroné, ou compte non autorisé | Recopiez `SPOTIFY_CLIENT_SECRET` ; ajoutez le compte dans *User Management* (§3, point 4) ; détails dans `storage/logs/laravel.log` |
| Vous avez ouvert `http://localhost:8000` et la connexion échoue | Spotify n'accepte que `127.0.0.1` | Utilisez toujours `http://127.0.0.1:8000` sur le PC |
| Message « compte non Premium » alors que vous l'êtes | Abonnement souscrit après la connexion | Déconnectez-vous et reconnectez-vous : le statut est relu à chaque connexion |

### Lecture

| Symptôme | Cause probable | Solution |
| --- | --- | --- |
| Rien ne se passe à l'ajout du premier morceau | « Démarrer la session » n'a pas été cliqué | Cliquez dessus sur l'écran hôte |
| Les morceaux Spotify sont sautés avec « Lecture impossible » | Navigateur sans DRM, navigation privée, ou compte non Premium | Utilisez Chrome ou Edge en fenêtre normale ; dans Firefox, autorisez « Lire le contenu protégé par DRM » (Paramètres → Général) |
| Les morceaux Spotify sont sautés avec un message d'authentification | Session Spotify expirée ou accès révoqué | Retour à l'accueil, déconnexion, reconnexion |
| Pas de son alors que la barre avance | Onglet ou PC en sourdine | Clic droit sur l'onglet → « Réactiver le son » ; vérifiez la sortie audio (HDMI) du PC |
| Les morceaux ne s'enchaînent pas toujours | Onglet hôte en arrière-plan | Gardez l'onglet hôte au premier plan |

### Recherche

| Symptôme | Cause probable | Solution |
| --- | --- | --- |
| « YouTube momentanément indisponible » | Clé absente ou erronée, API non activée, ou quota épuisé | Vérifiez `YOUTUBE_API_KEY` et que « YouTube Data API v3 » est activée (§4). Le journal indique la raison : `quotaExceeded`, `API_KEY_INVALID`, `accessNotConfigured` |
| Seuls des résultats YouTube s'affichent | L'hôte n'est pas Premium (c'est voulu), ou clés Spotify erronées | Si l'hôte est Premium, vérifiez les clés Spotify ; le journal indique l'erreur |
| Aucun résultat, aucune erreur | Moins de 3 caractères | Tapez au moins 3 caractères |
| Résultats Spotify d'un autre pays ou absents | Mauvais marché | Ajustez `SPOTIFY_MARKET` puis `sail restart reverb scheduler` |

### Musique de fond

| Symptôme | Cause probable | Solution |
| --- | --- | --- |
| « Playlist introuvable » avec un lien Spotify | Playlist éditoriale ou générée par Spotify, ou playlist privée d'un autre compte | Utilisez une de vos playlists, ou copiez les titres dans une nouvelle playlist |
| Vos playlists privées n'apparaissent pas dans la liste | Autorisation accordée avant l'ajout de la fonctionnalité | Déconnectez-vous puis reconnectez-vous avec Spotify |
| Pas de liste de playlists Spotify, seulement un champ de lien | Compte non Premium | Utilisez une playlist YouTube |
| « Playlist introuvable ou privée » avec un lien YouTube | Playlist privée, ou « mix » généré (`list=RD…`) | Passez la playlist en « Non répertoriée » ou « Publique » dans YouTube |
| La musique s'arrête quand la file est vide | Pas de musique de fond | Bandeau « Musique de fond » sur l'écran hôte → **Choisir une playlist** |

### Téléphones et temps réel

| Symptôme | Cause probable | Solution |
| --- | --- | --- |
| « This site can't be reached » en scannant le QR code ; l'écran hôte affiche « Cette adresse ne fonctionne que sur ce PC » | Le QR code pointe vers `127.0.0.1`, qui désigne le téléphone lui-même : `JUKEBOX_PUBLIC_URL` est vide | Renseignez-le avec l'IP du PC et le port (§8.2), puis rechargez l'écran hôte |
| Les autres appareils n'y arrivent pas pare-feu activé, mais y arrivent pare-feu coupé | La règle « Jukebox » ne couvre pas le bon port (créée avant le passage au port 8000, elle autorise 80) | Vérifiez avec `Get-NetFirewallRule -DisplayName "Jukebox" \| Get-NetFirewallPortFilter` : `LocalPort` doit valoir `{8000, 8080}`. Sinon : `Remove-NetFirewallRule -DisplayName "Jukebox"`, puis recréez-la avec la commande du §8.3 |
| Un téléphone Android échoue dans Chrome (`ERR_TOO_MANY_RETRIES`) alors que Firefox ou les autres appareils y arrivent | Chrome n'a pas l'autorisation Android d'accéder au réseau local | Paramètres → Applications → Chrome → Autorisations → **Appareils à proximité** → Autoriser (libellé variable selon la marque), puis rouvrez le lien. Ou ouvrez le lien avec un autre navigateur |
| Un seul téléphone échoue, pour une autre raison, alors que les autres appareils y arrivent | Un réglage du téléphone détourne la connexion | Essayez un autre navigateur ; dans Chrome, désactivez « Toujours utiliser des connexions sécurisées » et « DNS sécurisé » ; coupez VPN, « DNS privé », « Wi-Fi sécurisé » (Samsung) ou « Relais privé iCloud » (iPhone). Tapez l'adresse avec `http://` |
| Le téléphone n'arrive pas à ouvrir la page | Pare-feu, mauvaise IP, pas le même Wi-Fi, ou « Wi-Fi invités » isolé | Testez `http://IP/up` depuis le téléphone (§8.5) ; vérifiez le profil réseau « Privé » (§8.3) |
| Page blanche sur le téléphone, alors que le PC l'affiche | L'interface est servie par le serveur de développement | Arrêtez `sail npm run dev`, lancez `sail npm run build`. Si le problème persiste, supprimez le fichier `public/hot` (`rm public/hot`) |
| Indicateur « Reconnexion… » ou « Hors ligne » permanent | Reverb arrêté, ou port 8080 bloqué | `sail ps` (le service `reverb` doit tourner) ; `sail logs reverb` ; ouvrez le port 8080 dans le pare-feu |
| La file ne se met pas à jour toute seule | Même cause que ci-dessus | Idem. En attendant, recharger la page affiche toujours l'état exact |
| Un invité doit ressaisir son pseudo à chaque visite | Navigation privée sur le téléphone | Utilisez une fenêtre normale |

---

## 12. Lancer les tests

Tests backend (PHP, base PostgreSQL `testing` créée automatiquement par Sail ; aucun appel réel à Spotify ou YouTube) :

```bash
sail artisan test
```

Tests frontend :

```bash
sail npm test
```

Vérification des types TypeScript :

```bash
sail npm run typecheck
```

Si la base `testing` n'existe pas (volume PostgreSQL créé avant l'ajout de ce projet) :

```bash
docker compose exec pgsql psql -U sail -d jukebox -c "CREATE DATABASE testing"
```

---

## 13. Limites à connaître

- **La lecture réelle Spotify et YouTube n'a pas encore été testée** avec de vraies clés : les adaptateurs suivent la documentation officielle, mais votre premier essai (§7) en est la vraie validation. La détection de fin d'un morceau Spotify est le point le plus fragile ; si les morceaux Spotify ne s'enchaînent pas, signalez-le.
- **L'écran hôte doit être le PC qui fait tourner l'application** (contrainte Spotify, voir §0). Pour piloter depuis une autre machine, il faudrait servir l'application en HTTPS (par exemple derrière un tunnel ou un nom de domaine) et déclarer cette adresse dans le tableau de bord Spotify.
- **Un seul onglet hôte à la fois** : deux onglets hôte ouverts jouent le son en double.
- **Accès par le code** : toute personne qui connaît le code d'un salon peut le rejoindre et voir la file. Il n'y a pas de limite au nombre d'ajouts par invité.
- **Laravel 11 ne reçoit plus de correctifs de sécurité** : n'exposez pas l'application sur Internet en l'état, et prévoyez la montée vers Laravel 12 ou 13.
- **Quota YouTube** : environ 90 recherches différentes par jour.

Pour comprendre le code fichier par fichier, ou faire évoluer l'application : [`docs/`](docs/README.md). Les hypothèses, limites détaillées et évolutions suggérées sont dans [`docs/F-cloture.md`](docs/F-cloture.md).
