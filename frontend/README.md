# frontend/

Application statique de NEV Climate Data (HTML + Tailwind CSS v4, sans framework/bundler). Fusion du HTML NEV existant (contenu métier) et du template Tailwind "Play" (TailGrids, licence MIT - structure et langage visuel), entièrement reskinnée avec l'identité verte du projet. Connectée aux vraies données et à l'authentification du backend (A2.1, A2.2, bloc d'intégration de l'authentification).

## Structure

```
frontend/
├── index.html               Accueil
├── data.html                 Données (filtres + tableau + pagination - GET /api/funding réel, A2.2)
├── visualizations.html       Visualisations (graphiques Chart.js, données de démo)
├── reports.html               Rapports & analyses
├── sources.html                Sources de données
├── about.html                  À propos (mission, objectifs, vision)
├── api-docs.html              Documentation API
├── login.html                  Connexion (formulaire réel, POST /api/auth/login)
├── account-profile.html        Mon profil (GET /api/auth/me)
├── account-api-keys.html       Mes clés API (CRUD complet, POST/GET/DELETE /api/api-keys)
├── 404.html                     Page non trouvée
├── assets/
│   ├── images/logo/             Logo NEV Climate Data (SVG, clair/foncé)
│   ├── images/favicon.svg
│   └── js/
│       ├── main.js                Menu mobile, header sticky, panneau de filtres
│       ├── api.js                  GET /api/funding (window.NevApi)
│       └── auth.js                 Session JWT/refresh, clés API (window.NevAuth)
├── src/
│   ├── input.css                 Thème Tailwind v4 (palette verte, @theme)
│   └── css/tailwind.css          CSS compilé (généré - ne pas éditer à la main)
├── package.json
└── package-lock.json
```

Chaque page est un fichier HTML autonome (pas de moteur de templating) : la barre de navigation et le pied de page sont dupliqués à l'identique dans chaque fichier, à l'image de la structure d'origine du template Play.

## Prérequis

Node.js + npm (utilisés uniquement pour compiler Tailwind CSS - aucune dépendance côté exécution, les pages sont du HTML statique).

## Installer les dépendances

```bash
cd frontend
npm install
```

## Compiler le CSS

```bash
npm run build      # une fois, minifié
npm run dev         # recompilation automatique pendant le développement
```

## Ouvrir le site

Fichiers statiques - servir le dossier (ne pas ouvrir `index.html` directement en `file://` : les appels `fetch()` vers le backend ont besoin d'une vraie origine HTTP pour que CORS s'applique) :

```bash
python3 -m http.server 8123 --directory frontend
```

## Où le backend est-il ? (résolution automatique)

`assets/js/api.js` et `assets/js/auth.js` déduisent l'origine du backend de celle de la page elle-même (`window.location.hostname`) plutôt qu'une valeur codée en dur - rien à modifier entre les deux environnements où ce projet est vu :

| Où la page est ouverte | Backend déduit |
|---|---|
| `http://localhost:8123` (tunnel SSH local) | `http://localhost:8080` |
| `https://<nom-du-codespace>-8123.app.github.dev` | `https://<nom-du-codespace>-8080.app.github.dev` |

Voir la section « Base de l'URL de l'API » du `README.md` racine pour le détail, et le point d'attention n°10 (« Un port de Codespace en visibilité "private" ... ») si les appels API échouent avec une erreur ressemblant à du CORS alors que la config CORS du backend est correcte.

## Identité visuelle

Palette verte définie dans `src/input.css` (`@theme`) : `--color-primary` (vert principal, boutons/liens actifs), `--color-deep`/`--color-deep-2`/`--color-deep-3` (vert profond, hero/footer/CTA), `--color-surface`/`--color-surface-alt` (surfaces très légèrement verdâtres), plus 3 couleurs de statut hors palette verte (`--color-status-demo`, `--color-status-validated`, `--color-status-review`) pour les badges de qualité de donnée - volontairement distinctes du vert pour rester lisibles comme un système de statut à part.

## Authentification

- **Connexion** (`login.html`) : formulaire réel contre `POST /api/auth/login`. Redirige vers `account-profile.html` en cas de succès.
- **Session** (`assets/js/auth.js`, `window.NevAuth`) : JWT + refresh token en `localStorage` (`nev_token` / `nev_refresh_token`), rafraîchissement automatique sérialisé (le refresh token étant à usage unique côté backend, un seul rafraîchissement en vol à la fois protège contre une race condition si deux requêtes expirent simultanément). `authorizedFetch()` réessaie automatiquement une fois après un 401.
- **Mon profil** (`account-profile.html`) : email + rôle via `GET /api/auth/me`, lien vers « Mes clés API », déconnexion.
- **Mes clés API** (`account-api-keys.html`) : création (révélation unique de la clé brute + bouton copier), liste (statut, quota, dates), révocation - CRUD complet contre `POST/GET/DELETE /api/api-keys` (A1.5).
- **Navbar dynamique** : les 9 pages existantes affichent « Connexion » ou l'email de l'utilisateur + « Déconnexion » selon l'état de la session (`#auth-nav-slot`).

Pages protégées (`account-profile.html`, `account-api-keys.html`) : redirigent vers `login.html` si aucune session valide (`NevAuth.requireAuth()`).

## État des données affichées

- **`data.html`** : données réelles, issues de `GET /api/funding` (A2.1/A2.2) - filtres, pagination, et 4 états d'interface (chargement, erreur, vide, données).
- **Toutes les autres pages** (statistiques du Hero, graphiques de `visualizations.html`, `sources.html`, `reports.html`) : données encore **statiques et temporaires**, en attente des tâches A2.5/A2.6/A2.7/A2.13 du plan d'implémentation.
