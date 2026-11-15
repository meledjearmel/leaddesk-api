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

### D-012 · Changement de poste : les accès découlent du seul poste actuel (RM-09)

- **Décision** : quand un agent change de poste, rien n'est cumulé : ses accès sont ceux de son nouveau poste. L'affectation précédente est close et gardée dans son parcours (`agent_position_assignments`) ; un index empêche deux affectations ouvertes.
- **Raison** : RM-09 est ouverte, mais `CONCEPTION_MODULES_LEADDESK.md` (10.1) dit déjà que « ses accès résultent du nouveau poste ». C'est aussi l'option la plus simple et la plus sûre.
- **Alternatives** : cumul temporaire des accès de l'ancien poste pendant une passation.

### D-013 · Aucune clé d'API sur le serveur

- **Décision** : un fournisseur IA est décrit par son nom, son type et son adresse éventuelle ; le serveur ne reçoit et ne stocke aucune clé. Les clés restent dans le stockage sécurisé de Windows, sur chaque ordinateur.
- **Raison** : les modèles sont appelés depuis l'application de bureau ; le serveur n'en a jamais besoin. Les documents demandent un « espace sécurisé » hors des dossiers de projet.
- **Conséquence** : sur un second ordinateur, le CEO ressaisit ses clés.
- **Alternatives** : clés chiffrées côté poste puis synchronisées par le serveur (comme la sauvegarde de clé de signature de Folio), si la ressaisie gêne en phase 7.

### D-014 · Catalogue de fournisseurs ouvert

- **Décision** : `config/leaddesk.php` liste les types de fournisseurs connus ; un type absent du catalogue est accepté.
- **Raison** : les documents demandent un catalogue qui évolue, pas une liste fermée.
- **Alternatives** : liste fermée validée par le serveur.

### D-015 · Mise en service d'un agent

- **Décision** : un agent est créé en brouillon ; il passe en service dès qu'il a au moins un modèle IA dont le fournisseur est toujours connecté (RM-06). Ni poste ni accès MCP ne sont exigés. Un agent en service ne peut pas perdre son dernier modèle : il faut d'abord le repasser en brouillon. Un agent peut recevoir plusieurs modèles, de plusieurs fournisseurs, chacun avec un usage, dont un seul par défaut (RM-10).
- **Raison** : application directe de RM-06 et RM-10 ; garder la règle vraie dans le temps évite un agent « en service » incapable de travailler.
- **Alternatives** : repasser automatiquement l'agent en brouillon quand il perd son dernier modèle.

### D-016 · Composition du modèle studio

- **Décision** : le modèle studio est un fichier de données (`database/templates/studio.php`) copié dans l'entreprise à sa création, dans une seule transaction. Il crée les huit départements, les pôles de Technique (Architecture, Développement, Déploiement, Maintenance) et de Qualité et sécurité (Tests et validation, Sécurité), et 26 postes occupés par 26 agents, tous en brouillon : un responsable par unité, plus un poste opérationnel par département sans pôle et par pôle. Aucun modèle IA, aucun accès, aucune clé.
- **Raison** : les documents fixent les départements et les pôles ; `CONCEPTION_STUDIO_VIRTUEL.md` indique « un employé opérationnel en plus de son responsable ». Intitulés, missions, prénoms et couleurs sont des contenus de départ que le CEO adapte (RM-04).
- **Alternatives** : un modèle plus petit (responsables seulement) ou stocké en base pour être modifiable sans déploiement.

### D-017 · « CEO virtuel » et COO : un seul poste dans le modèle

- **Décision** : le modèle crée un seul poste de tête, « COO », au département Direction ; il rend compte au CEO humain et tous les autres postes lui rendent compte, directement ou non. C'est lui qui tient le rôle que la synthèse appelle « CEO virtuel » (alerter, présenter les propositions, recevoir les missions).
- **Raison** : `CONCEPTION_LEADDESK.md` réserve « CEO » à l'utilisateur humain et nomme « COO » le rôle de coordination ; la synthèse parle d'un « CEO virtuel » sans le définir comme un poste distinct. Créer deux postes aurait inventé une exigence.
- **À confirmer par Armel** : le « CEO virtuel » est-il bien le COO, ou un agent distinct placé au-dessus de lui ? Le changement se limite au fichier du modèle.

## Phase 2 — Socle application (partie serveur)

### D-018 · Un seul événement temps réel, issu du journal

- **Décision** : chaque entrée du journal de traçabilité émet `company.changed` sur le canal privé `company.{id}`. L'événement ne contient que l'événement, la ressource et son identifiant ; l'application recharge ensuite par l'API. Abonnement autorisé par jeton sur `POST /api/v1/broadcasting/auth`.
- **Raison** : un seul mécanisme couvre toutes les ressources présentes et futures ; aucune donnée métier ne transite par le canal ; le temps réel ne peut pas diverger de l'API.
- **Alternatives** : un événement par ressource avec sa charge utile complète (moins d'appels, mais deux formats à tenir à jour).

### D-019 · Répartition du travail entre deux sessions

- **Décision** : le 15 novembre 2026, Armel a réparti le travail : cette session reste sur `leaddesk-api` et enchaîne les parties serveur des phases 3 puis 4 ; une autre session tient `leaddesk-app` (elle y a déjà construit l'écran du bureau vivant) et y branche l'API d'après le contrat `docs/api/`.
- **Raison** : deux sessions qui écrivent dans le même dépôt risquent de s'écraser.
- **Conséquence** : les critères de fin des phases 2 et suivantes, qui demandent un parcours complet dans l'application, se valident quand les deux côtés sont prêts. Le journal distingue ce qui est fait côté serveur.

## Phase 3 — Travail, règles et décisions (partie serveur)

### D-020 · Workflows : étapes libres, catégories fixes

- **Décision** : un workflow a une portée (`activity` ou `task`, RM-18) qui ne change plus, et une liste ordonnée d'étapes aux libellés libres. Chaque étape porte une catégorie parmi cinq (`todo`, `in_progress`, `review`, `done`, `cancelled`) qui dit au logiciel où en est le travail. La première étape est l'étape de départ. Les passages d'une étape à l'autre sont libres, sauf l'entrée dans une étape `done` d'une tâche, réservée à la confirmation du responsable (RM-19).
- **Raison** : le plan laisse « statuts et champs configurables détaillés » à trancher en phase 3. Les libellés et le nombre d'étapes restent configurables (RM-03) ; cinq catégories suffisent pour le Dashboard, le bureau et les garde-fous, sans coder de statut en dur.
- **Alternatives** : transitions configurables étape par étape (plus riche, à ajouter si le besoin apparaît) ; statuts fixes (contraire à RM-03).

### D-021 · Champs configurables d'un type d'activité

- **Décision** : un type d'activité définit ses champs (`key`, `label`, `type`, `required`, `options`) parmi six natures : texte, texte long, nombre, date, oui/non, choix dans une liste. Les valeurs d'une activité sont validées avec la définition **actuelle** du type ; une valeur dont le type ne définit pas le champ n'est pas gardée.
- **Raison** : détail laissé ouvert par le plan (phase 3). Six natures couvrent les exemples des documents (site, liste de contrôle, dépôt, critères) sans moteur de formulaires.
- **Alternatives** : champs fichier, relation ou formule, à ajouter au besoin.

### D-022 · Propagation d'un changement de type (RM-21)

- **Décision** : une activité ne copie rien de son type : elle lit ses champs et ses workflows au moment de l'usage. Quand le workflow d'un type change, ou qu'une étape est retirée, les activités et tâches en cours vont sur la première étape de même catégorie du nouveau parcours, sinon sur l'étape de départ.
- **Raison** : RM-21 impose la propagation ; garder la catégorie conserve l'avancement réel du travail.
- **Rappel** : l'écart avec RM-23 et RM-27 (qui ne propagent pas) est voulu par les documents ; l'interface devra le rendre explicite.

### D-023 · Un agent agit par l'en-tête `X-LeadDesk-Agent`

- **Décision** : les agents s'exécutent dans l'application du CEO, qui appelle l'API avec le jeton du CEO et l'en-tête `X-LeadDesk-Agent: <id>`. L'agent doit appartenir à l'entreprise et être en service, sinon 403. Tout ce qu'il fait est journalisé à son nom (RM-34). Sans en-tête, c'est le CEO qui agit.
- **Raison** : en V1 le seul compte est celui du CEO et l'exécution est locale ; un jeton par agent n'apporterait rien de plus tant que tout tourne sur son ordinateur.
- **Limite connue** : l'en-tête est déclaratif. Les droits fins par poste (RM-14) viendront avec le MCP interne en phase 6.
- **Alternatives** : un jeton Sanctum par agent, avec ses capacités.

### D-024 · Qui confirme la fin d'une tâche (RM-19) et qui affecte (RM-08)

- **Décision** : une tâche appartient à une équipe (une des unités de son activité). Une étape de catégorie `done` ne s'atteint que par `confirm-completion`, réservé au **responsable de l'équipe** : l'agent en service qui occupe le poste de responsable de l'unité, sinon celui de l'unité parente la plus proche. Le CEO ne confirme lui-même que si aucune unité de la chaîne n'a de responsable en service. L'affectation des agents à une tâche est ouverte au CEO et, parmi les agents, au seul responsable de l'équipe.
- **Raison** : RM-19 est un garde-fou : le CEO ne doit pas pouvoir court-circuiter le responsable par habitude. Le repli vers le CEO évite qu'une tâche reste bloquée dans une entreprise sans responsable (entreprise vierge, postes en brouillon).
- **Alternatives** : laisser le CEO confirmer dans tous les cas ; bloquer sans repli.

### D-025 · Règles : niveaux, héritage et versions

- **Décision** : une règle est posée au niveau de l'entreprise (`unit_id` vide) ou d'une unité, et vaut pour ce niveau et tous ceux en dessous. Une règle d'un niveau inférieur peut se rattacher à une règle héritée avec la relation `precise` ou `reinforce` ; aucune relation ne permet d'assouplir, et une règle que d'autres précisent ne se retire pas (RM-24). Modifier une règle crée une nouvelle version (compteur ; le détail avant/après est dans le journal).
- **Limite** : les documents évoquent aussi des règles par projet, poste ou agent. Seuls l'entreprise et les unités sont couverts ici ; les autres niveaux pourront s'ajouter sans changer le principe.
- **Limite** : le serveur ne juge pas le texte d'une règle. Qu'une règle « renforce » réellement celle qu'elle vise relève de la décision du CEO, seul à pouvoir la créer.
- **Alternatives** : table de versions dédiée ; niveaux projet, poste et agent dès maintenant.

### D-026 · Seul le CEO change une règle ; les agents proposent (RM-25, RM-26, RM-28)

- **Décision** : créer, modifier, retirer ou étendre une règle est refusé (403) à toute requête faite au nom d'un agent, COO compris. Un agent dépose une proposition (`POST /rule-proposals`) qui décrit le problème, la règle visée, le changement, les personnes touchées, les effets attendus et les risques ; elle devient une décision en attente et ne modifie rien. À l'approbation, la proposition est revérifiée : si la règle visée a changé entre-temps, l'approbation est refusée (409).
- **Raison** : RM-25 est un interdit absolu ; le garantir sur le serveur le rend indépendant du comportement des agents.

### D-027 · Portée d'une règle dans le temps (RM-27)

- **Décision** : chaque version d'une règle a une date d'entrée en vigueur. Elle s'applique aux activités créées à partir de cette date ; pour les activités déjà en cours, il faut que le CEO l'étende explicitement (`extend_to_running`, à la création, à la modification, à l'approbation, ou par `POST /rules/{id}/extend-to-running`). Une nouvelle version repart sans extension.
- **Raison** : application directe de RM-27, et de l'écart voulu avec RM-21.

### D-028 · File unique des décisions du CEO

- **Décision** : table `decisions` avec une nature (`kind`), un contenu libre (`payload`), un auteur et quatre états (`pending`, `needs_info`, `approved`, `rejected`). Seul le CEO approuve, refuse ou demande des précisions ; l'auteur répond aux précisions. Première nature : `rule_change`. Les limites de coût, accès, Skills et embauches s'y ajouteront comme de nouvelles natures.
- **Raison** : le plan demande « un seul mécanisme réutilisé partout ».

## Phase 4 — Moteur d'exécution des agents (partie serveur)

### D-029 · Ordinateurs hôtes et exécutions de tâche

- **Décision** : l'application enregistre son ordinateur (`installation_id` généré à l'installation) et donne signe de vie régulièrement. Une exécution (`task_runs`) relie une tâche, un agent en service affecté à cette tâche et un ordinateur hôte ; une tâche n'a qu'une exécution ouverte. Si l'hôte ne donne plus signe de vie depuis un délai réglable (120 s par défaut), l'exécution est affichée « en attente de l'hôte » sans changer d'état : elle reprend dès que l'hôte revient. Terminer une exécution ne termine pas la tâche (RM-19).
- **Raison** : « application fermée = tâche en attente, reprise à la réouverture » ; l'hôte doit être connu pour chaque tâche et visible depuis un autre ordinateur (phase 7).
- **Alternatives** : marquer l'exécution « interrompue » par une tâche planifiée (plus de mécanique, même effet à l'écran).

### D-030 · Coûts réels, estimations et limites (RM-35, RM-36, RM-37)

- **Décision** : l'hôte envoie un enregistrement par appel de modèle ou d'outil (fournisseur et modèle réels, jetons, coût en millionièmes de dollar ou vide si le tarif est inconnu). Il est rattaché à l'agent, la tâche, l'activité et le projet. L'estimation est un champ distinct de la tâche et n'est jamais additionnée au réel. RM-37 étant ouverte, les limites se règlent aux trois niveaux cités par les documents — entreprise, projet, tâche — sans aucune valeur par défaut : pas de limite réglée, pas de pause. À chaque coût reçu, la limite la plus précise atteinte met l'exécution en pause (`paused_cost`) et crée une décision `cost_limit`. Le CEO approuve en donnant une nouvelle limite supérieure au dépensé (l'exécution reprend) ou refuse (l'exécution est arrêtée). Personne ne relance une exécution en pause pour coût autrement.
- **Raison** : RM-36 est un garde-fou : la reprise doit passer par la décision du CEO, sur le serveur.
- **Limite connue** : le coût est déclaré par l'hôte après l'appel ; le dépassement vaut donc au plus le coût d'un appel. La méthode d'estimation (RM-37) reste à définir : le serveur ne fait que garder l'estimation saisie.
- **Alternatives** : limite par activité ou par agent ; devise autre que le dollar (le champ est en millionièmes pour rester exact).

### D-031 · Permissions d'exécution par poste et dossiers autorisés

- **Décision** : le plan laisse le « modèle de permissions d'exécution » à concevoir en début de phase 4. Chaque poste règle, outil par outil, un mode : `allow` (autorisé d'office), `ask` (soumis à l'approbation du CEO à chaque usage) ou `deny`. Le catalogue des outils locaux (lire, écrire, supprimer vers la corbeille, lancer une commande, consulter une page web) et le mode par défaut sont des réglages ; le défaut est prudent : `ask`. L'agent hérite du poste qu'il occupe ; sans poste, tout est interdit. Avant d'utiliser un outil, l'hôte demande `POST /task-runs/{id}/approvals` : réponse immédiate pour `allow` et `deny`, décision `tool_approval` en attente pour `ask`. Seul le CEO règle les permissions et autorise les dossiers d'un projet (documentation et code, par ordinateur) ; changer un chemin vaut nouvelle autorisation. Le serveur ne garde que les chemins.
- **Raison** : même logique que les accès MCP (liés au poste, RM-11) ; `CONCEPTION_LEADDESK.md` §6 décrit l'autorisation unique des deux dossiers.
- **Limite** : l'hôte applique ces permissions ; le serveur ne peut pas empêcher un appel local qu'il ne voit pas. Les accès au serveur restent, eux, contrôlés côté serveur.
- **Alternatives** : permissions par agent ; liste blanche de commandes.

## Phase 5 — Bureau vivant (partie serveur)

### D-032 · Réglages de l'entreprise et horloge du bureau (RM-50, RM-51)

- **Décision** : les réglages synchronisés vivent dans `companies.settings` ; seules les valeurs changées par le CEO y sont stockées, les autres prennent la valeur par défaut de `config/leaddesk.php` (60 minutes réelles par journée simulée, bornes 5 à 1440). `GET /company/settings` renvoie aussi l'horloge : le temps simulé se calcule depuis l'heure Unix et le réglage, il est donc identique sur tous les ordinateurs et rien n'est stocké. Seul le CEO modifie les réglages.
- **Raison** : RM-50 (réglable, jamais en dur) ; RM-51 (le temps simulé ne touche à aucune donnée) ; un calcul déterministe évite toute dérive entre ordinateurs.
- **Limite** : changer le rapport fait sauter l'heure simulée affichée ; c'est sans conséquence puisqu'elle ne sert qu'à l'ambiance.
- **Alternatives** : une heure de départ stockée à chaque changement (continuité visuelle, mais un état de plus à synchroniser) ; une table de réglages clé-valeur.

### D-033 · Conversation et contexte partagé d'une activité

- **Décision** : une activité a une conversation de travail (`activity_messages`, natures `message` et `handoff`, rattachement facultatif à une de ses tâches) et un contexte partagé (`activity_context_items` : titre, contenu, ordre). Participants : le CEO, les agents affectés à une tâche de l'activité et les responsables de ses équipes ; seuls eux écrivent. Une passation ne porte que des références de fichiers (libellé, chemin, ordinateur de l'entreprise), jamais leur contenu. Un message ne se modifie pas ; seul le CEO le retire (corbeille). Le contexte partagé est aussi remis à l'agent dans son contexte de tâche (RM-33). Le temps réel passe par `company.changed` (D-018) ; `after_id` recharge seulement les nouveaux messages.
- **Raison** : le plan (phase 5) et la référence `07-interface-sombre-conversation.png` demandent ces deux onglets ; le modèle de contexte partagé restant « à concevoir », la forme la plus simple (notes ordonnées) est retenue. « Les agents ne prennent la parole que lorsqu'ils sont concernés » (`CONCEPTION_STUDIO_VIRTUEL.md`) devient la règle des participants.
- **Alternatives** : conversations indépendantes des activités (direction, réunions), à ajouter plus tard ; participants choisis à la main ; contexte versionné.

## Gestion des salles

### D-034 · Unités système RH et Moyens généraux

- **Décision** (Armel, 15 novembre 2026 ; D-012 et D-013 de `leaddesk-app`) : toute entreprise, même vierge, possède une unité RH et une unité Moyens généraux, repérées par `units.system_role` (`hr`, `facilities`). `EnsureSystemUnits`, appelé par `CreateCompany` après le modèle, crée celles qui manquent : unité de premier niveau avec un poste de responsable en brouillon, sans agent. Le modèle studio les porte lui-même : « Ressources humaines » (`hr`) et le pôle « Moyens généraux » (`facilities`) sous « Administration et finances », ce qui garde ses huit départements (RM-04).
- **Règles** : nom et couleur modifiables ; suppression refusée (409) ; `system_role` n'est pas modifiable par l'API ; index unique partiel `(company_id, system_role)`.
- **Raison** : la RH propose les agents et les Moyens généraux proposent l'aménagement ; le code s'appuie sur le rôle, jamais sur le nom (RM-03).
- **Écart** : exception à RM-02 (« une entreprise vierge existe sans unité, poste ni agent ») ; voir la proposition ci-dessous.
- **Note** : le code a été poussé par erreur dans le commit `20d4407`, avec la conversation d'une activité.

### D-035 · Missions du CEO et poste qui les reçoit

- **Décision** : la barre « Donner une mission » crée une mission (`missions` : consigne, projet facultatif, état `received` → `in_progress` → `done`, ou `cancelled`). Elle va à l'agent en service qui occupe le **poste des missions** : réglage `mission_position_id` de l'entreprise, par défaut le premier poste de responsable qui ne rend compte à personne (le COO du modèle studio). Sans agent en service sur ce poste, la mission est refusée (409). Seul le CEO confie et annule ; seul l'agent destinataire (ou le CEO) la rattache à l'activité qui la réalise et la rend avec un compte rendu.
- **Raison** : D-017 reste à confirmer par Armel ; un réglage rend la réponse indifférente au code : si le « CEO virtuel » devient un poste distinct, il suffit de le choisir.
- **Alternatives** : mission créée directement comme activité (mélange la demande et son organisation) ; destinataire choisi à chaque mission.

### D-036 · Plans d'aménagement sur le serveur

- **Décision** : table `office_plans` (version croissante par entreprise, état `proposed` / `accepted` / `rejected` / `superseded` / `stale`, plan au format `OfficePlan.toJson()` de l'application, poste et agent qui proposent, plan de départ, motif, commentaire de décision, `softDeletes`). Un seul plan en vigueur par entreprise (index unique partiel). Routes `/api/v1/office/…` : catalogue des salles, départements, plan en vigueur, enregistrement par le CEO, vérification, liste, accepter, refuser.
- **Règles** (D-012 de `leaddesk-app`) : seul le CEO accepte, refuse ou dessine (403 pour un agent) ; un plan dessiné par le CEO entre en vigueur sans approbation ; l'ancien plan passe « remplacé » ; toute proposition en attente passe « périmée » quand le plan en vigueur change ; une proposition est revalidée contre l'organisation actuelle avant d'être acceptée.
- **Mêmes règles que l'application** : `OfficePlanValidator` reprend `OfficePlan.validate` (mêmes contrôles, même ordre, mêmes messages). Les cas de `tests/Fixtures/office/validation-cases.json` sont calculés par l'application et rejoués ici. Le catalogue `resources/office/room-types.json` est exporté de `room_catalog.dart` ; l'application vérifie qu'il est identique.
- **Départements** : les unités de premier niveau ; l'effectif compte les postes de l'unité et de ses sous-unités. Dans un plan, `department` est l'identifiant de l'unité, en texte.
- **Journal** : chaque changement d'état est tracé (RM-34) et diffusé par Reverb ; le plan lui-même n'est pas recopié dans le journal.
- **Alternatives** : stocker le plan dans les réglages de l'entreprise (pas d'historique ni de propositions) ; passer par les décisions génériques (`Decision`) dès maintenant, ce qui sera fait par le MCP à l'étape 3.
## Propositions de mise à jour des documents de conception

- `REGLES_METIER_LEADDESK.md` (RM-02) : une entreprise vierge contient toujours les unités RH et Moyens généraux, chacune avec un poste de responsable sans agent (D-034).
- `PLAN_DE_REALISATION_LEADDESK.md`, section 2 « Organisation du dépôt » : remplacer la proposition de dépôt unique par les deux dépôts `leaddesk-api` et `leaddesk-app` (D-001).
