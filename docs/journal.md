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
- Base PostgreSQL locale `leaddesk` à créer avant le premier `migrate` hors tests.
