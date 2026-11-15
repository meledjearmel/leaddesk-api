# CLAUDE.md — leaddesk-api (serveur)

LeadDesk est un logiciel pour créer et piloter des entreprises d'agents IA. Ce dépôt est le **serveur Laravel**. L'application de bureau vit dans le dépôt frère `leaddesk-app` (dossier `../apps`).

## Documentation de conception (à lire avant toute tâche importante)

Dossier : `C:\Users\armel.meledje\OneDrive\Documents\LeadDeck`

1. `PLAN_DE_REALISATION_LEADDESK.md` — feuille de route (phases 0 à 12, critères de fin).
2. `Synthese_decisions_fonctionnelles_V1.md` — décisions confirmées, propositions, sujets ouverts.
3. `REGLES_METIER_LEADDESK.md` — règles RM-01 à RM-63.
4. `CONTEXTE_LEADDESK_CLAUDE_CODE.md` — architecture confirmée.
5. `inspiration_design/` — références visuelles (page publique).

Ne jamais modifier ces documents : proposer les changements dans `docs/decisions.md`. Ne pas transformer une proposition en décision, ne pas inventer d'exigence.

## Rôle du dépôt et frontière serveur / application

**Le serveur détient** : données métier (entreprise, organisation, postes, agents, activités, tâches, règles, décisions, historique, traçabilité, coûts), designs synchronisés, diffusion temps réel (Reverb), relais du contrôle à distance, MCP interne, versions de l'application, page publique (Inertia/React).

**Le serveur ne détient jamais** : les fichiers de travail des agents (livrables, code, documents), ni l'exécution des agents. Les appels aux modèles IA et les outils locaux tournent dans l'application de bureau. Les téléversements par adresse signée servent uniquement aux installateurs et aux éléments graphiques créés par le CEO.

## Pile

Laravel 13, PHP 8.4+, PostgreSQL (SQLite en mémoire pour les tests, job CI PostgreSQL), Sanctum (jetons de l'application), Reverb (temps réel), `laravel/mcp` + Passport (OAuth des connecteurs), Fortify, Inertia 3 + React 19 + Tailwind 4 (page publique et back-office web), Pint, Larastan, PHPUnit, Laravel Boost (`AGENTS.md`, à suivre).

## Contrat d'API

`docs/api/` est la **source de vérité** de l'API `/api/v1` consommée par l'application. Toute évolution d'une route met à jour le contrat **dans le même commit**. L'application s'y conforme.

## Vocabulaire

Même vocabulaire que les documents ; anglais dans le code, français dans l'interface et la documentation.

| Document | Code |
|---|---|
| entreprise | `Company` |
| unité | `Unit` |
| poste | `Position` |
| agent | `Agent` |
| type d'activité | `ActivityType` |
| activité | `Activity` |
| tâche | `Task` |
| projet | `Project` |
| règle | `Rule` |
| décision | `Decision` |
| Skill | `Skill` |

## Conventions

- Répondre et documenter en français ; code et identifiants en anglais ; messages de commit courts en français, au présent.
- Travail direct sur `main` : coder → tester → corriger → retester ; commit puis push seulement quand tout est vert. Jamais de commit avec des tests rouges.
- Un changement qui touche l'API et l'application : un commit dans chaque dépôt, même intitulé.
- Chaque règle métier (RM-xx) concernée par un changement est couverte par un test qui la cite.
- Les règles déclarées configurables ne sont jamais codées en dur (RM-03).
- Suppression logique (`softDeletes`) sur les données métier ; seeders idempotents.
- Aucun secret commité (`.env`, clés). Les clés des fournisseurs IA restent chiffrées côté poste.
- Aucune action destructive (suppression, réécriture d'historique, push forcé) sans accord d'Armel.
- Référence de patterns (MCP, téléversements signés, versions) : `C:\Users\armel.meledje\PhpstormProjects\folio\folio` — lire, adapter, ne jamais modifier.

## Commandes

```bash
composer install && npm install
php artisan migrate
composer run dev                 # serveur, file, Vite
php artisan reverb:start         # temps réel
php artisan test                 # tests
vendor/bin/pint                  # style
vendor/bin/phpstan analyse       # Larastan
npm run check && npm run types:check
composer ci:check                # tout ce que joue la CI
```

## Suivi

- `docs/decisions.md` — décisions prises pendant le code (décision, raison, alternatives) et propositions de mise à jour des documents.
- `docs/journal.md` — bilan court de chaque phase (fait, testé, reste à faire).
- `docs/prototypes/` — comptes rendus des prototypes de la phase 0.
