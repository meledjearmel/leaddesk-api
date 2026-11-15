# Contrat d'API LeadDesk — `/api/v1`

Ce dossier est la **source de vérité** de l'API JSON consommée par l'application de bureau (`leaddesk-app`). Toute évolution d'une route met à jour ce contrat dans le même commit ; l'application s'y conforme.

## Conventions générales

- **Base** : `{APP_URL}/api/v1`.
- **Format** : JSON (`Accept: application/json`), UTF-8, dates ISO 8601 en UTC.
- **Authentification** : jeton Sanctum dans l'en-tête `Authorization: Bearer <jeton>`.
- **Erreurs** : `401` sans jeton valide, `403` action interdite, `404` ressource absente, `422` validation (`{ "message": "...", "errors": { "champ": ["..."] } }`).
- **Versionnement** : un changement incompatible crée `/api/v2` ; `/api/v1` n'est jamais cassée en place.

## Routes

### `GET /user`

Renvoie le compte authentifié.

- **Authentification** : requise.
- **200** :

```json
{
  "id": 1,
  "name": "Ada",
  "email": "ada@example.com",
  "email_verified_at": "2026-11-15T08:00:00.000000Z",
  "created_at": "2026-11-15T08:00:00.000000Z",
  "updated_at": "2026-11-15T08:00:00.000000Z"
}
```

- **401** : jeton absent ou invalide.

## Temps réel (Reverb)

Protocole Pusher sur `ws(s)://{REVERB_HOST}:{REVERB_PORT}/app/{REVERB_APP_KEY}`. Les canaux et événements sont décrits ici au fur et à mesure de leur création.
