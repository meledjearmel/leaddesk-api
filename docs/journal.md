# Journal — leaddesk-api

Bilan court de chaque étape et de chaque phase : fait, testé, reste à faire.

## Étape A — Dépôts et outillage (15 novembre 2026)

**Fait**

- Dépôt git indépendant, dépôt GitHub privé `leaddesk-api`.
- PostgreSQL préparé (`.env.example`), Sanctum (jetons), Reverb, `laravel/mcp`, Passport (migrations et configuration publiées), Laravel Boost.
- Route versionnée `GET /api/v1/user` protégée par Sanctum ; contrat dans `docs/api/`.
- CI : Pint, Larastan, contrôles front, tests ; second job sur PostgreSQL 17.
- `CLAUDE.md`, `docs/decisions.md`, `docs/journal.md`.

**Testé**

- 45 tests PHPUnit verts, Pint et Larastan sans erreur, `npm run check` et `types:check` verts.

**Reste à faire**

- Configuration complète du MCP interne et de l'OAuth Passport (phase 6).
- PostgreSQL local : identifiants à fournir ; en attendant le développement local tourne sur SQLite (D-007).

## Phase 1 — Socle serveur (15 novembre 2026)

**Fait**

- Comptes par jeton Sanctum (inscription, connexion, déconnexion par appareil) ; un compte = une entreprise (RM-01).
- Entreprise vierge ou issue du modèle studio (RM-02, RM-04) : huit départements, pôles, 26 postes et 26 agents en brouillon.
- Organisation en unités imbriquées de profondeur libre (RM-03) ; postes avec mission, responsabilités, supérieur et état.
- Agents à identité stable, un seul poste à la fois, parcours conservé (RM-05) ; mise en service dès qu'un modèle IA est configuré (RM-06).
- Fournisseurs et modèles IA, plusieurs modèles par agent, aucune clé sur le serveur (RM-10).
- Journal de traçabilité automatique et consultable (RM-34).
- Corbeille et restauration pour unités, postes et agents.
- Contrat d'API généré par Scramble (`docs/api/openapi.json`), vérifié par un test.

**Testé**

- 131 tests PHPUnit verts, Pint et Larastan sans erreur ; règles couvertes par des tests qui les citent : RM-01, RM-02, RM-03, RM-04, RM-05, RM-06, RM-07, RM-10, RM-34.
- Aucune ressource d'une autre entreprise n'est atteignable (testé sur chaque ressource).

**Critère de fin : atteint**

- API documentée et testée : oui.
- Création d'une entreprise vierge et d'une entreprise studio : oui.
- Changement de poste d'un agent sans perte d'historique : oui.

**Reste à faire / à confirmer**

- Le « CEO virtuel » est-il le COO du modèle (D-017) ?
- Diffusion temps réel des changements : phase 2.
