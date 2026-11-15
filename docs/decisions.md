# Décisions prises pendant le code — leaddesk-api

Chaque entrée : décision, raison, alternatives. Les propositions de mise à jour des documents de conception sont listées en fin de fichier (les documents eux-mêmes ne sont pas modifiés).

## Étape A — Dépôts et outillage

### D-001 · Deux dépôts indépendants au lieu d'un dépôt unique

- **Décision** : `api/` → `leaddesk-api`, `apps/` → `leaddesk-app`, chacun avec son `CLAUDE.md`, sa CI et son dossier `docs/`.
- **Raison** : demandé explicitement dans la mission ; le plan ne proposait le dépôt unique (`server/`, `desktop/`) qu'à titre de proposition.
- **Alternatives** : dépôt unique avec `server/` et `desktop/`.

### D-002 · Contrat d'API généré par Scramble

- **Décision** : `docs/api/openapi.json` (OpenAPI 3.1) est généré depuis le code par Scramble (`php artisan scramble:export`) et commité ; `docs/api/README.md` garde les conventions et le temps réel. Un test échoue si le contrat commité ne correspond plus au code.
- **Raison** : demandé par Armel le 15 novembre 2026 (la première version était un Markdown rédigé à la main) ; même outil que Folio ; le contrat ne peut plus diverger du code.
- **Alternatives** : Markdown rédigé à la main (abandonné).

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

### D-007 · Développement local sur SQLite

- **Décision** : le `.env` local reste sur SQLite ; `.env.example` et la production visent PostgreSQL, validé à chaque push par le job CI `postgres`.
- **Raison** : le PostgreSQL installé sur le poste demande un mot de passe qui n'est pas connu ; SQLite ne bloque pas le travail et Folio fonctionne ainsi.
- **Alternatives** : PostgreSQL local dès que les identifiants sont fournis (changer trois lignes du `.env`).

## Phase 1 — Socle serveur

### D-008 · Inscription possible depuis l'application

- **Décision** : `POST /api/v1/auth/register` crée un compte et délivre un jeton. L'inscription web du starter kit (Fortify) reste en place.
- **Raison** : le plan demande un compte CEO dès la phase 1 ; le choix « inscription depuis le site ou depuis l'application » n'est tranché qu'en phase 12. Offrir les deux est le plus simple et ne ferme aucune option.
- **Alternatives** : inscription sur le site seulement (retirer la route), ou dans l'application seulement.

### D-009 · Vérification de l'e-mail non exigée par l'API

- **Décision** : l'API accepte un compte dont l'e-mail n'est pas encore vérifié.
- **Raison** : aucun document ne l'exige ; l'exiger bloquerait le premier lancement de l'application.
- **Alternatives** : middleware `verified` sur les routes métier, à ajouter si Armel le souhaite.

### D-010 · Identifiants entiers et suppression logique

- **Décision** : clés primaires entières ; toutes les données métier utilisent la suppression logique (corbeille) ; les contraintes d'unicité sont des index partiels (`WHERE deleted_at IS NULL`).
- **Raison** : simplicité ; une donnée à la corbeille ne doit pas bloquer une nouvelle création.
- **Alternatives** : UUID ou ULID (utile si des données sont un jour créées hors ligne par l'application).

### D-011 · Journal de traçabilité automatique

- **Décision** : table `audit_entries` alimentée par le trait `Audited` des modèles (création, modification avec valeurs avant et après, mise à la corbeille, restauration). Auteur : le CEO connecté, sinon « system » ; un agent se déclarera avec `Audit::actingAs()`. Les champs masqués d'un modèle ne sont jamais écrits dans le journal.
- **Raison** : RM-34 doit être couvert dès le départ, sans dépendre de la discipline de chaque contrôleur.
- **Alternatives** : journalisation explicite dans chaque action (plus fine, mais facile à oublier) ; les événements métier qui ne sont pas des modifications de modèle l'utiliseront en complément.

## Propositions de mise à jour des documents de conception

- `PLAN_DE_REALISATION_LEADDESK.md`, section 2 « Organisation du dépôt » : remplacer la proposition de dépôt unique par les deux dépôts `leaddesk-api` et `leaddesk-app` (D-001).
