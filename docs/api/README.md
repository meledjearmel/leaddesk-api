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

Le temps réel n'entre pas dans OpenAPI ; il est décrit ici. Protocole Pusher sur `ws(s)://{REVERB_HOST}:{REVERB_PORT}/app/{REVERB_APP_KEY}`. Les canaux et événements sont ajoutés à ce document au fur et à mesure de leur création.
