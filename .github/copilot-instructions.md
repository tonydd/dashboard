# Instructions Copilot — Dashboard

Ce dépôt contient un dashboard domestique rendu côté serveur, une API Symfony, des intégrations externes et un serveur de signalisation WebRTC pour les caméras. Avant toute modification, lire les fichiers voisins et suivre leur style : plusieurs comportements sont propres à ce projet et ne sont pas décrits par les conventions Symfony seules.

## Stack et points d’entrée

- Backend : PHP `>=8.4`, Symfony `7.4`, Doctrine ORM 3, MySQL 8, Twig.
- Frontend : JavaScript natif, Twig, Symfony AssetMapper et ImportMap; pas de compilation Vite/Webpack ni de framework frontend.
- Routes applicatives définies par attributs PHP dans `symfony/src/Controller/`; découverte configurée dans `symfony/config/routes.yaml`.
- Services `App\` autowirés/autoconfigurés dans `symfony/config/services.yaml`. Les paramètres des intégrations externes sont liés aux arguments de services dans ce fichier.
- Développement local via Docker Compose : Symfony sur `localhost:8000`, MySQL sur `localhost:33061`, signalisation caméra sur `localhost:8081`. Voir `docs/LOCAL_SETUP.md`.

## Carte du dépôt

- `symfony/src/Controller/` : pages Twig et endpoints API. Contrôleurs principaux : `DashboardController`, `AuthenticationController`, `CameraController`, `StatusController`; intégrations et données : `SolarController`, `ThermorController`, `WaterHeaterController` (écran
admin `/water-heater` : état, historique et bouton barre d'actions),
`WaterQualityController`, `FuelController`; API recettes : `RecipeController`, `IngredientsController`, `TagController`, `UnitController`; fonds d’écran : `BackgroundController`.
- `symfony/src/Service/` : logique métier et accès aux fournisseurs externes. Garder les contrôleurs minces; y déléguer calculs, règles métier, appels externes et gestion d’état réutilisable.
- `symfony/src/Entity/`, `Repository/`, `Enum/` : modèle Doctrine, accès aux données et types partagés. Les entités Doctrine sont exclues de l’autodéclaration de services.
- `symfony/src/Model/` : objets de transport (par exemple `RecipeDto`).
- `symfony/src/Scheduler/` : `ScheduleProvider`, messages planifiés et handlers. `symfony/src/Messenger/` contient le middleware de heartbeat.
- `symfony/src/Command/` : commandes console, dont les commandes de collecte, de mot de passe et `app:camera:ws`.
- `symfony/templates/` : gabarits Twig; les pages du dashboard sont dans `templates/dashboard/`.
- `symfony/assets/` : `app.js` importe `dashboard.js`, `camera.js`, `status.js` et `styles/dashboard.css`.
- `symfony/migrations/` : migrations Doctrine versionnées; `symfony/tests/` regroupe tests `Controller/`, `Service/` et `Repository/`.
- `Docker/`, `compose.yml`, `compose.prod.yml`, `scripts/`, `docs/` : conteneurs, déploiement, opérations et documentation. En production, le déploiement passe par `scripts/deploy-prod.sh`; voir `docs/PRODUCTION_DEPLOYMENT.md`.

## Choisir le bon point d’extension

### Ajouter une page

1. Ajouter une route par attribut dans le contrôleur du domaine concerné (ou un nouveau contrôleur si le domaine est distinct).
2. Créer le gabarit dans `symfony/templates/`, en général sous `templates/dashboard/`, et étendre `base.html.twig`.
3. Injecter uniquement les données nécessaires au rendu depuis le contrôleur, en réutilisant services et repositories existants.
4. Les pages protégées vérifient actuellement `$this->getUser()` et rendent `dashboard/login.html.twig` si l’utilisateur est anonyme; elles ne redirigent pas nécessairement.

### Ajouter une API

- Déclarer une route PHP avec les méthodes HTTP explicites et conserver le format de réponse utilisé par les routes voisines (`JsonResponse`/`$this->json()`). Les routes sous `/api` exigent `ROLE_VIEWER` par défaut, sauf exceptions publiques configurées dans `symfony/config/packages/security.yaml`.
- Pour les mutations, contrôler explicitement le rôle requis (`#[IsGranted('ROLE_ADMIN')]` lorsque l’opération est administrative) et le CSRF. Le navigateur obtient son jeton via `GET /api/session` puis l’envoie dans `X-CSRF-Token`; les contrôleurs utilisent les attributs `#[IsCsrfTokenValid('api', ...)]` sur leurs routes mutantes. Ne pas supposer que le CSRF est activé automatiquement pour chaque nouvelle action.
- L’authentification est une session Symfony alimentée par `POST /api/login` en JSON. `/api/login` et `/api/session` sont publics; les erreurs d’authentification API ont une réponse JSON. Tout utilisateur possède toujours `ROLE_VIEWER`; réserver les opérations sensibles à `ROLE_ADMIN`.
- Valider les entrées et répondre avec des statuts adaptés. Un payload peut être mappé sur un DTO avec `#[MapRequestPayload]` ou décodé explicitement avec `JSON_THROW_ON_ERROR`; dans ce dernier cas, traiter séparément JSON invalide (400) et valeurs non prises en charge (souvent 422). Ajouter des contraintes au DTO ou une validation explicite : ne pas considérer le mapping comme une validation métier.
- Pour les entités exposées, vérifier les groupes du Serializer (`recipe:list`, `recipe:detail` dans le domaine recettes) et ne pas exposer involontairement les champs internes.
- La commande `RecipeController` est un exemple d’API métier relationnelle. Les APIs Solar/Thermor/eau/carburant servent surtout le dernier état enregistré; ne pas ajouter un appel réseau synchrone à l’affichage sans nécessité.

### Persister des données

- Pour une nouvelle entité métier durable ou relationnelle, ajouter une entité Doctrine, son repository si une requête dédiée le justifie, et une migration sous `symfony/migrations/`. Ne pas modifier une migration déjà appliquée pour changer le schéma : créer une nouvelle version.
- Pour un réglage, cache ou état applicatif qui n’est pas une entité métier, utiliser la table `key_value` existante. Ajouter une valeur à `App\Enum\KeyValueKey`, puis lire/écrire via `KeyValueRepository::getValue()`, `getUpdatedAt()` et `setValue()`. `setValue()` crée/met à jour l’enregistrement, fixe `updated_at` et flush; ne pas ajouter une table dédiée à un simple réglage.
- Les valeurs de `key_value` sont JSON et peuvent être de formes variées : définir et valider la structure au niveau du service qui les possède. Prévoir une valeur par défaut et le comportement de repli quand une valeur manque ou est invalide.
- Les relevés d’intégration ont des entités/repositories spécifiques (par exemple `SolarStat`, `WaterHeaterStat`, `FuelStat`) en plus de clés `KeyValue`; inspecter le flux existant du domaine avant de choisir où stocker une nouvelle donnée.
- Garder la persistance dans Doctrine/repositories et les règles d’accès dans les services; lorsqu’une opération touche plusieurs entités, utiliser une transaction et assurer son rollback en cas d’erreur.

### Ajouter une intégration ou une collecte planifiée

- Encapsuler l’accès fournisseur dans un service de `symfony/src/Service/`; injecter les clients et paramètres nécessaires. Ajouter les variables d’environnement dans les fichiers modèles/configuration pertinents et les déclarer/lier dans `symfony/config/services.yaml`. Les secrets restent côté serveur : ne jamais les intégrer dans Twig, JavaScript ou une réponse API.
- Les collectes récurrentes suivent le modèle `symfony/src/Scheduler/Message/*Message.php` + `symfony/src/Scheduler/Handler/*Handler.php`, avec la périodicité déclarée dans `symfony/src/Scheduler/ScheduleProvider.php`. Un handler délègue au service métier, qui transforme et persiste les données. Ajouter un test de service et, au besoin, de handler/contrôleur.
- Les consommateurs Messenger utilisent le transport configuré dans `symfony/config/packages/messenger.yaml`; les erreurs peuvent finir dans le transport `failed`. Le Scheduler doit être exécuté par un consommateur `messenger:consume scheduler_default` (local : commande indiquée dans `docs/LOCAL_SETUP.md`; en production, service Docker `scheduler`). Une nouvelle tâche planifiée n’est pas active si ce worker n’est pas en marche.
- Pour les intégrations HTTP, conserver les états d’indisponibilité et erreurs déjà pratiqués; éviter que l’échec ou la lenteur d’un fournisseur bloque le rendu initial du dashboard. La météo et les actualités, par exemple, sont demandées côté navigateur après le rendu initial.

### Modifier le frontend

- Les pages partagent `templates/base.html.twig`, qui charge l’entrée ImportMap `app`. Ajouter les modules JS dans `assets/` et les importer explicitement depuis `assets/app.js` si nécessaire; les fichiers sous `assets/` sont exposés par AssetMapper.
- Utiliser Twig pour le HTML initial et des attributs `data-*` pour transmettre aux scripts les valeurs/états nécessaires. Les comportements du dashboard, de la caméra et du statut sont séparés dans leurs modules existants; le style partagé est dans `assets/styles/dashboard.css`.
- Garder les appels d’API relatifs (`/api/...`) et les identifiants DOM cohérents entre Twig et JavaScript. Ne pas ajouter de dépendance de build frontend sans raison et sans mettre à jour le mode de livraison.
- La caméra utilise un serveur de signalisation WebSocket distinct (`app:camera:ws`). Le backend délivre un jeton temporaire via `/api/camera/token`; la signalisation relaie SDP/ICE et le média passe en pair-à-pair WebRTC. Préserver la validation du rôle, du jeton et le routage WebSocket lors de toute évolution caméra.

## Tests et commandes

- Tests PHPUnit : `cd symfony && php bin/phpunit`; test ciblé : `php bin/phpunit tests/Service/DashboardSettingsServiceTest.php` (remplacer par le fichier concerné).
- Tests de contrôleurs : utiliser `WebTestCase` et vérifier authentification, autorisation, CSRF, codes HTTP et JSON. Tests de services : PHPUnit avec mocks des repositories/clients, et tests des valeurs limites et états de repli.
- Avant de conclure une modification de schéma, vérifier au minimum la migration et l’exécution des tests concernés; migrations locales : `cd symfony && php bin/console doctrine:migrations:migrate --no-interaction`.
- Diagnostic utile : `cd symfony && php bin/console debug:router`, `php bin/console about`. Vérifier une route, pas seulement son action, quand l’API change.
- La suite existante couvre les contrôleurs Dashboard, Authentication, Camera, Status et Thermor, plusieurs services et `KeyValueRepository`. Il n’y a pas de test frontend dédié ni de test de contrôleur recette actuellement; ajouter une couverture ciblée lorsqu’on modifie ces surfaces.

## Intégration Thermor / Overkiz (chauffe-eau) — limites d’appels

Le chauffe-eau passe par trois nuages en chaîne (Atlantic → Magellan →
Overkiz) et leurs quotas ne sont pas documentés ; un dépassement peut
entraîner un blocage de plusieurs heures du compte. Toutes les règles
ci-dessous sont implémentées dans `symfony/src/Service/ThermorApiRateLimiter.php`
et `ThermorService.php` :

- **Tout appel HTTP sortant** vers Atlantic/Magellan/Overkiz doit passer par
  `ThermorApiRateLimiter::acquire()` (bucket de jetons persisté dans la table
  `key_value`, clé `ThermorApiRateLimit`) : régime soutenu max **2 appels/min**
  (1 jeton/30 s), rafale max **10 appels**, partagé entre tous les consommateurs
  (scheduler, requêtes web, sondes manuelles).
- Un 429 ou `errorCode: QUOTA_EXCEEDED` déclenche `penalize()` : backoff
  exponentiel **60 s → 30 min** bloquant tous les sites d’appel ; il est levé
  par le prochain succès (`recordSuccess()`).
- Le polling planifié (`ThermorMessage`, `ScheduleProvider`) s’appuie sur le
  cache du setup (`SETUP_CACHE_TTL` ≈ 55 s) : plusieurs consommateurs sur une
  même minute partagent un seul appel `/setup`. Ne jamais rafraîchir plus vite
  que ce TTL et ne jamais désactiver ce cache.
- La session Overkiz (JSESSIONID) coûte 3 appels (token Atlantic, token
  Magellan, login Overkiz) et vit 30 min : la réutiliser via le cache plutôt
  que se reconnecter. En cas d’erreur de session, une seule reconnexion puis
  un seul retry (voir `fetchSetup()`).
- Les commandes vers le chauffe-eau passent par `exec/apply` ; chaque appel
  peut porter **plusieurs commandes** (batch) : regrouper les `refresh*` au
  lieu d’émettre un appel par commande. Les commandes `refresh*` demandent à
  la gateway de relire l’appareil en Modbus et de pousser les états vers le
  cloud (utile car l’appareil ne pousse pas de lui-même plus souvent que
  ~10 min) ; les résultats sont asynchrones (laisser ~20-30 s avant de relire
  `/setup`).
- Pour du débogage ou de l’exploration manuelle de l’API : écrire un script
  temporaire réutilisant `ThermorApiRateLimiter` (session/identifiants dans
  `symfony/.env`, jamais committés), **sauvegarder chaque réponse dans un
  fichier temporaire** sous `symfony/var/` (non versionné) et réanalyser les
  fichiers locaux au lieu de refaire des appels. Ne jamais relancer un script
  de sonde sans revoir d’abord les réponses déjà enregistrées.

## Configuration et précautions opérationnelles

- Le setup local est documenté dans `docs/LOCAL_SETUP.md`. Avec Docker, `DATABASE_URL` Symfony doit utiliser l’hôte `db`; depuis l’hôte, MySQL est publié sur `127.0.0.1:33061`.
- Ne jamais committer `.env`, `symfony/.env.local`, secrets, dumps de base ou données personnelles. `BACKGROUND_TOKEN` est une exception de visibilité à traiter avec soin : puisqu’il est ajouté à une URL d’image demandée par le navigateur, il devient visible côté client.
- Les variables de configuration sont principalement passées par Compose puis liées dans `config/services.yaml`; lors de l’ajout d’une variable, vérifier le passage jusqu’au conteneur local et au déploiement de production, pas uniquement le code PHP.
- **Gestion des variables d'environnement en production** : les secrets et variables d'environnement de production sont stockés dans une archive chiffrée GPG. Si une modification ou l'ajout d'une variable d'environnement en production est nécessaire, l'agent ne doit pas modifier l'archive directement mais **fournir explicitement à l'opérateur les commandes et scripts à exécuter** :
  - `scripts/add-prod-env.sh NOM VALEUR FICHIER` (pour ajouter ou remplacer une variable dans un fichier cible, ex. `.env` ou `.env.compose.prod`).
  - `scripts/update-prod-env.sh NOM VALEUR` (pour mettre à jour une variable existante dans tous les fichiers `.env*`).
  - `scripts/copy-prod-env.sh SRC CIBLE VAR[=CIBLE_VAR]…` et `scripts/read-prod-env.sh NOM FICHIER` pour inspection/copie.
  Voir `docs/PRODUCTION_DEPLOYMENT.md` pour le détail de l'archive et le déploiement via `scripts/deploy-prod.sh`.
- Les routes API et interfaces de production doivent rester derrière HTTPS afin de protéger le cookie de session. Les ports de conteneurs de production sont en loopback et l’accès externe passe par le proxy; le chemin WebSocket caméra doit préserver l’upgrade.
- Après une évolution, mettre à jour les tests et la documentation locale/déploiement si le démarrage, une variable d’environnement, une migration, un worker ou le proxy change.
