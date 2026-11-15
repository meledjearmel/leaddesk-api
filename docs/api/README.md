# Contrat d'API LeadDesk — `/api/v1`

Ce dossier est la **source de vérité** de l'API JSON consommée par l'application de bureau (`leaddesk-app`). Toute évolution d'une route met à jour ce contrat dans le même commit ; l'application s'y conforme.

## Conventions générales

- **Base** : `{APP_URL}/api/v1`.
- **Format** : JSON (`Accept: application/json`), UTF-8, dates ISO 8601 en UTC.
- **Authentification** : jeton Sanctum dans l'en-tête `Authorization: Bearer <jeton>`. Un jeton par appareil.
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

## Objets

### Compte (`User`)

```json
{
    "id": 1,
    "name": "Ada Lovelace",
    "email": "ada@example.com",
    "email_verified_at": null,
    "company": null
}
```

`company` vaut `null` tant que le compte n'a pas créé son entreprise, sinon un objet Entreprise.

### Entreprise (`Company`)

```json
{
    "id": 1,
    "name": "Atelier Nord",
    "template": "blank",
    "created_at": "2026-11-15T08:00:00.000000Z",
    "updated_at": "2026-11-15T08:00:00.000000Z"
}
```

`template` : configuration de départ, `blank` (vierge) ou `studio` (modèle studio de développement).

## Comptes et jetons

### `POST /auth/register`

Crée un compte et délivre un jeton. Limité à 10 appels par minute.

- **Corps** : `name`, `email`, `password`, `password_confirmation`, `device_name` (nom de l'appareil, 100 caractères au plus).
- **201** : `{ "token": "…", "user": Compte }`.
- **422** : champ manquant, e-mail déjà pris, mots de passe différents.

### `POST /auth/token`

Connexion. Limité à 10 appels par minute.

- **Corps** : `email`, `password`, `device_name`.
- **200** : `{ "token": "…", "user": Compte }`.
- **422** : identifiants incorrects (même message que l'e-mail soit inconnu ou le mot de passe faux).

### `DELETE /auth/token`

Révoque le jeton de l'appareil appelant ; les autres appareils restent connectés.

- **204**.

### `GET /user`

- **200** : `{ "data": Compte }`.

## Entreprise

Un compte correspond à une seule entreprise (RM-01).

### `GET /company`

- **200** : `{ "data": Entreprise }`.
- **404** : le compte n'a pas encore d'entreprise.

### `POST /company`

Crée l'entreprise du compte (RM-02).

- **Corps** : `name` (120 caractères au plus), `template` (`blank` ou `studio`).
- **201** : `{ "data": Entreprise }`. Une entreprise vierge est créée sans unité, poste ni agent.
- **409** : le compte a déjà une entreprise.
- **422** : nom ou modèle invalide.

### `PATCH /company`

- **Corps** : `name`.
- **200** : `{ "data": Entreprise }`.

## Temps réel (Reverb)

Protocole Pusher sur `ws(s)://{REVERB_HOST}:{REVERB_PORT}/app/{REVERB_APP_KEY}`. Les canaux et événements sont décrits ici au fur et à mesure de leur création.
