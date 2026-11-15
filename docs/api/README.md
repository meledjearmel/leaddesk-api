# Contrat d'API LeadDesk — `/api/v1`

Ce dossier est la **source de vérité** de l'API JSON consommée par l'application de bureau (`leaddesk-app`).

- **`openapi.json`** — le contrat, au format OpenAPI 3.1, **généré depuis le code** par [Scramble](https://scramble.dedoc.co). Il décrit chaque route, ses paramètres, ses réponses et ses objets. Ne pas le modifier à la main.
- **Version interactive** : `/docs/api` sur le serveur (accessible en environnement local).

## Mettre le contrat à jour

Après toute modification d'une route, d'une validation ou d'une ressource :

```bash
php artisan scramble:export
```

puis commiter `docs/api/openapi.json` **dans le même commit**. Le test `tests/Feature/Api/ApiContractTest.php` échoue tant que le fichier commité ne correspond pas au code.

Les descriptions viennent des commentaires des contrôleurs (première ligne : titre de la route ; suite : description) et des règles de validation.

## Conventions générales

- **Base** : `{APP_URL}/api/v1`.
- **Format** : JSON (`Accept: application/json`), UTF-8, dates ISO 8601 en UTC.
- **Authentification** : jeton Sanctum dans l'en-tête `Authorization: Bearer <jeton>`. Un jeton par appareil.
- **Agent agissant** : quand un agent agit (et non le CEO lui-même), l'application ajoute l'en-tête `X-LeadDesk-Agent: <id de l'agent>`. L'agent doit appartenir à l'entreprise et être en service, sinon `403`. Ses actions sont journalisées à son nom ; certaines routes lui sont réservées ou interdites selon son poste (voir leur description).
- **Enveloppe** : une ressource est renvoyée sous la clé `data` ; une liste sous `data` (tableau).
- **Périmètre** : toutes les données métier sont celles de l'entreprise du compte connecté. Une ressource d'une autre entreprise répond `404`.
- **Erreurs** :
    - `401` jeton absent ou invalide ;
    - `403` action interdite ;
    - `404` ressource absente (ou compte sans entreprise) ;
    - `409` conflit avec l'état actuel ;
    - `422` validation : `{ "message": "...", "errors": { "champ": ["..."] } }` ;
    - `429` trop de tentatives.
- **Versionnement** : un changement incompatible crée `/api/v2` ; `/api/v1` n'est jamais cassée en place.

## Temps réel (Reverb)

Le temps réel n'entre pas dans OpenAPI ; il est décrit ici.

- **Connexion** : protocole Pusher sur `ws(s)://{REVERB_HOST}:{REVERB_PORT}/app/{REVERB_APP_KEY}`.
- **Abonnement à un canal privé** : `POST /api/v1/broadcasting/auth` avec le jeton Sanctum et le corps `{ "socket_id": "…", "channel_name": "private-…" }` ; la réponse `{ "auth": "…" }` est transmise à Reverb dans `pusher:subscribe`.

### Canal `private-company.{id}`

Réservé au CEO de l'entreprise `{id}`.

#### Événement `company.changed`

Émis après chaque entrée du journal de traçabilité, donc après chaque création, modification, mise à la corbeille, restauration, changement de poste, changement de modèles ou mise en service. Il ne transporte aucune donnée métier : l'application s'en sert pour savoir quoi recharger par l'API.

```json
{
    "id": 42,
    "event": "agent.position_changed",
    "kind": "change",
    "actor_type": "ceo",
    "subject_id": 7,
    "occurred_at": "2026-11-15T08:00:00.000Z"
}
```

- `id` : identifiant de l'entrée du journal (`GET /audit-entries`).
- `event` : `<ressource>.<verbe>` — ressources `company`, `unit`, `position`, `agent`, `ai_provider`, `ai_model` ; verbes `created`, `updated`, `deleted`, `restored`, et pour un agent `position_changed` et `ai_models_changed`.
- `subject_id` : identifiant de la ressource concernée.

L'événement passe par la file d'attente et n'est émis qu'une fois la transaction validée : un worker (`php artisan queue:work`) doit tourner à côté de Reverb.

## MCP interne — `/mcp/leaddesk`

Serveur MCP (protocole HTTP « streamable ») utilisé par les agents depuis l'application de bureau (D-038). Authentification : jeton Sanctum du CEO (`Authorization: Bearer …`) et agent dans l'en-tête `X-LeadDesk-Agent`, comme pour l'API. Tout est journalisé au nom de l'agent (RM-34).

| Outil | Rôle |
| --- | --- |
| `office.context` | Lecture seule : départements (effectif, couleur, activités), étiquettes d'activité, catalogue des salles, plan en vigueur, nombre de propositions en attente, règles et format du plan. |
| `office.propose_plan` | Propose un plan (`layout`, `reason`). Il est validé avec les mêmes règles que l'application ; invalide, il est refusé avec la liste des problèmes. Valable, il devient une proposition et une décision `office_plan` en attente du CEO. Réservé aux postes dont la permission `office.propose_plan` n'est pas `deny` (refusé par défaut). |

Accepter ou refuser se fait au choix par `/office/plans/{id}/accept|reject` ou par `/decisions/{id}/approve|reject` : les deux restent synchronisés. Une proposition périmée ferme sa décision (refusée, avec une note).
