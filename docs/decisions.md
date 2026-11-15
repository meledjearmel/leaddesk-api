# Décisions prises pendant le code — leaddesk-api

Chaque entrée : décision, raison, alternatives. Les propositions de mise à jour des documents de conception sont listées en fin de fichier (les documents eux-mêmes ne sont pas modifiés).

## Étape A — Dépôts et outillage

### D-001 · Deux dépôts indépendants au lieu d'un dépôt unique
- **Décision** : `api/` → `leaddesk-api`, `apps/` → `leaddesk-app`, chacun avec son `CLAUDE.md`, sa CI et son dossier `docs/`.
- **Raison** : demandé explicitement dans la mission ; le plan ne proposait le dépôt unique (`server/`, `desktop/`) qu'à titre de proposition.
- **Alternatives** : dépôt unique avec `server/` et `desktop/`.

### D-002 · Contrat d'API en Markdown
- **Décision** : `docs/api/README.md` rédigé à la main, source de vérité.
- **Raison** : le plus simple et réversible tant que l'API est petite ; lisible par les deux dépôts sans outillage.
- **Alternatives** : OpenAPI généré (Scramble, comme Folio) — à reconsidérer quand l'API grossit.

### D-003 · Tests sur SQLite en mémoire, job CI PostgreSQL
- **Décision** : la suite locale tourne sur SQLite en mémoire ; la CI rejoue migrations et tests sur PostgreSQL 17.
- **Raison** : même approche que Folio ; tests rapides en local, garantie PostgreSQL à chaque push.
- **Alternatives** : PostgreSQL partout (plus lent, dépend d'un service local).

### D-004 · Pas de client Echo dans le front web
- **Décision** : la configuration `@laravel/echo-react` ajoutée par l'installateur Reverb est retirée de `resources/js/app.tsx`.
- **Raison** : le temps réel est consommé par l'application Flutter ; la page publique n'en a pas besoin. Évite une dépendance Node inutile.
- **Alternatives** : installer `laravel-echo` et `pusher-js` — à faire si le back-office web a un jour besoin du temps réel.

### D-005 · Domaines de redirection OAuth du MCP restreints
- **Décision** : `config/mcp.php` n'autorise que claude.ai, claude.com, chatgpt.com, chat.openai.com et les clients locaux.
- **Raison** : même liste que Folio ; la valeur par défaut `*` est trop large.
- **Alternatives** : `*` (refusé).

### D-006 · `CLAUDE.md` versionné, fichiers Boost ignorés
- **Décision** : `CLAUDE.md` est commité ; `AGENTS.md`, `.mcp.json` et `boost.json` restent ignorés (générés par `php artisan boost:install`).
- **Raison** : la mission exige un `CLAUDE.md` par dépôt ; les fichiers Boost se régénèrent.
- **Alternatives** : tout versionner.

## Propositions de mise à jour des documents de conception

- `PLAN_DE_REALISATION_LEADDESK.md`, section 2 « Organisation du dépôt » : remplacer la proposition de dépôt unique par les deux dépôts `leaddesk-api` et `leaddesk-app` (D-001).
