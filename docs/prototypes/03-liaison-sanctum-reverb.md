# Prototype 3 — Liaison application–serveur (Sanctum + Reverb), côté serveur

**Date :** 15 novembre 2026 · **Code :** `prototypes/realtime/` · **Compte rendu complet et mesures :** `docs/prototypes/03-liaison-sanctum-reverb.md` du dépôt `leaddesk-app`.

## Ce qui a été construit

Routes jetables, chargées par `bootstrap/app.php` seulement si `PROTOTYPES_ENABLED=true` (jamais en production) :

- `POST /api/prototypes/realtime/token` : e-mail + mot de passe + nom d'appareil → jeton Sanctum, compte et nom du canal privé ;
- `POST /api/prototypes/realtime/ping` (jeton requis) : diffuse l'événement `prototype.ping` sur `private-prototypes.user.{id}` ;
- `POST /api/broadcasting/auth` (jeton requis) : signature d'abonnement aux canaux privés.

Le dossier `prototypes/` est chargé par l'autoload de développement (`Prototypes\`), analysé par Larastan et formaté par Pint.

## Résultat

- 5 tests (`tests/Feature/Prototypes/RealtimePrototypeTest.php`) : jeton délivré et utilisable sur `/api/v1/user`, mauvais mot de passe refusé, ping refusé sans jeton, événement diffusé sur le canal privé de l'appelant, autorisation de canal refusée sans jeton.
- Parcours réel validé depuis le client Dart : latence ping → événement de 59 ms en médiane (voir le compte rendu de l'application).

## Lancer le prototype

```bash
# .env : PROTOTYPES_ENABLED=true, BROADCAST_CONNECTION=reverb
php artisan migrate
php artisan serve --port=8123
php artisan reverb:start --port=8080
```

## Approche retenue pour la suite

- La route d'autorisation des canaux pour l'application est `POST /api/broadcasting/auth`, protégée par `auth:sanctum` (la route par défaut utilise la session web).
- Les événements métier seront diffusés sur des canaux privés par entreprise, via la file d'attente (`ShouldBroadcast`), et non en direct comme ici.
- La délivrance de jeton deviendra une route `/api/v1` documentée dans le contrat, avec révocation par appareil.
