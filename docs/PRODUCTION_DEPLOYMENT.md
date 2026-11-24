# Déploiement Docker en production

La production conserve Apache sur l'hôte pour les certificats TLS et les deux noms de domaine actuels. Docker fournit l'interface, l'API Symfony en `APP_ENV=prod`, MySQL, et un worker Scheduler indépendant. L'hôte n'a plus besoin de PHP, Composer, Node.js, MySQL ni Supervisor pour ces composants. L'image Symfony utilise PHP 8.4, conformément à la version minimale requise par le `composer.lock`, et Composer s'exécute uniquement pendant le build de l'image.

Les services exposent uniquement des ports loopback :

- `web` sert le frontend compilé et relaie `/api/` vers Symfony sur `127.0.0.1:9101` par défaut.
- `php` sert Symfony/Apache sur `127.0.0.1:9100` par défaut.
- `scheduler` exécute `messenger:consume scheduler_default` et redémarre automatiquement.
- `database` utilise un volume Docker persistant et n'expose aucun port à l'hôte.

## Apache hôte

Activer les modules `proxy` et `proxy_http`. Dans le VirtualHost frontend, remplacer le `DocumentRoot` et les anciennes règles de fallback Vue par ces relais :

```apache
ProxyPreserveHost On
ProxyPass /api/ http://127.0.0.1:9101/api/
ProxyPassReverse /api/ http://127.0.0.1:9101/api/
ProxyPass / http://127.0.0.1:9101/
ProxyPassReverse / http://127.0.0.1:9101/
```

Le VirtualHost de l'API doit aussi remplacer son `DocumentRoot` et son `FallbackResource` pour relayer vers Symfony :

```apache
ProxyPreserveHost On
ProxyPass / http://127.0.0.1:9100/
ProxyPassReverse / http://127.0.0.1:9100/
```

Garder les directives SSL et les certificats existants. Si le VirtualHost frontend conserve une règle dédiée pour `/api/random-image/`, la placer avant les règles génériques et la faire pointer vers le port frontend choisi. Tester les deux VirtualHosts et recharger Apache avant de basculer le trafic. Choisir deux ports loopback libres dans `.env.compose.prod`.

Comme le navigateur passe par le VirtualHost frontend, les variables frontend `VITE_API_BASE_URL`, `VITE_FUEL_API_URL`, `VITE_SOLAR_API_URL`, `VITE_WATER_HEATER_API_URL` et `VITE_WATER_QUALITY_API_URL` doivent utiliser les chemins relatifs `/api/...`. Un nom d'hôte API distinct crée des appels cross-origin et exige un certificat TLS valide pour ce nom ainsi qu'une configuration CORS correspondante. `VITE_BACKGROUND_URL` doit être `/api/background`; sans images `.jpg`, cette route répond `404`.

## Configuration et secrets

Le script de déploiement s'attend à trouver dans l'archive GPG, extraite sous `/dev/shm` :

- `.env` : variables Symfony et secrets backend existants.
- `.env.vue` : configuration Vite commune.
- `.env.production.vue` : configuration Vite de production.
- `.env.compose.prod` : paramètres MySQL Docker et, facultativement, le dossier hôte des fonds d'écran (`BACKGROUND_HOST_PATH`), basé sur `.env.compose.prod.example`.
- `database.sql.gz` : export initial de la base MySQL existante, requis uniquement lors du premier déploiement dans un volume vide.

Ces fichiers doivent être à la racine de l'archive, pas dans un sous-dossier. `.env.compose.prod.example` et `.env.production.vue.example` sont des modèles du dépôt, pas des fichiers à inclure dans l'archive. Le script exige les quatre premiers fichiers et exige le dump seulement si le volume MySQL Docker est vide.

`THERMOR_API_TOKEN` est facultatif : sans cette variable, la lecture de l'état du chauffe-eau reste disponible, mais les commandes de pilotage répondent `503` jusqu'à sa configuration.

Pour préparer l'archive, partir de l'archive actuelle, la déchiffrer dans un dossier temporaire sous `/dev/shm`, puis y ajouter ou remplacer `.env.compose.prod`. Copier les valeurs du modèle `.env.compose.prod.example` et renseigner les identifiants MySQL et deux ports loopback libres (`9100` pour l'API et `9101` pour le frontend par défaut). `BACKGROUND_HOST_PATH` est facultatif; s'il est omis, Compose utilise `./symfony/var/backgrounds` dans le checkout de déploiement. Générer des mots de passe MySQL distincts, de préférence hexadécimaux, et ne jamais committer le fichier réel. Recréer ensuite le `tar.gz`, le chiffrer avec le même mécanisme GPG que l'archive actuelle, puis vérifier que le script `gpg --decrypt ... | tar xz` peut le lire. Ne remplacer l'archive de production qu'après cette vérification.

Le backend utilise toujours `BACKGROUND_PATH` pour servir le fond d'écran quotidien via `/api/background`. Si aucun fichier `.jpg` n'est présent dans le dossier monté, le déploiement ne bloque plus, mais cette route ne pourra pas fournir d'image.

Pour créer le dump initial depuis la base MySQL actuelle, exécuter par exemple `mysqldump -u UTILISATEUR -p --single-transaction --routines --triggers --events --databases NOM_BASE | gzip > /dev/shm/database.sql.gz`, puis vérifier le résultat avec `gzip -t /dev/shm/database.sql.gz`. Le dump contient les données de production : le garder temporairement en RAM et le chiffrer dans l'archive. Conserver également une sauvegarde indépendante avant la bascule. Les redéploiements suivants n'ont pas besoin de `database.sql.gz` tant que le volume Docker contient déjà les tables.

Le fichier Symfony et les identifiants MySQL restent dans `/dev/shm`; le `.env` Symfony est injecté au runtime. Les seuls fichiers copiés temporairement dans le clone sont les paramètres `VITE_*` publics du frontend, après suppression du secret client Spotify. Ils sont supprimés avec le clone en fin de déploiement. Les mots de passe MySQL utilisés dans `DATABASE_URL` doivent être URL-safe; des secrets hexadécimaux conviennent.

Tout `VITE_*` est incorporé au bundle et visible dans le navigateur. Le composant `Spotify.vue` est le widget maison du tableau de bord : il affiche la lecture en cours et permet précédent, pause/reprise et suivant. Il utilise actuellement `VITE_SPOTIFY_CLIENT_SECRET` dans le navigateur pour obtenir et renouveler les jetons; ce secret serait donc récupérable par toute personne consultant le bundle. Le script l'exclut volontairement. En conséquence, une nouvelle connexion Spotify et le renouvellement des jetons échoueront tant que le widget n'aura pas été migré.

Pour une application frontend publique, PKCE (« Proof Key for Code Exchange ») permet d'utiliser le flux OAuth Authorization Code sans secret client : le navigateur génère une preuve temporaire, Spotify la lie à la demande d'autorisation, puis le navigateur doit fournir cette même preuve pour échanger le code contre des jetons. L'autre option est de déplacer l'échange et le renouvellement des jetons dans Symfony, où le secret peut rester côté serveur. Ni l'une ni l'autre migration n'est incluse dans le déploiement Docker décrit ici.

## Données MySQL

Le premier démarrage crée une base vide dans le volume `database_data`. Le script refuse d'appliquer les migrations si aucune table n'existe et qu'aucun export initial n'est fourni. Le dump `database.sql.gz` doit provenir d'une sauvegarde cohérente de la base actuelle; le script ne réimporte pas ce dump lors des déploiements suivants si la base contient déjà des tables.

Avant le tout premier déploiement, créer l'export depuis l'ancienne base avec `mysqldump --single-transaction --databases <nom_base> | gzip` et l'ajouter à l'archive chiffrée. Conserver également une sauvegarde indépendante avant toute bascule.

Prévoir des sauvegardes régulières du volume de production. Les migrations sont exécutées après le démarrage et la vérification de MySQL.

## Déploiement et exploitation

Remplacer l'ancien script de déploiement par `scripts/deploy-prod.sh`. Il clone le dépôt, déchiffre les secrets en RAM, construit et démarre les services, importe le dump uniquement au premier démarrage et applique les migrations. Le service Docker `scheduler` remplace l'ancien worker Supervisor. Pour les déploiements suivants, l'archive peut omettre `database.sql.gz`.

Après avoir vérifié que le worker Docker `dashboard-prod-scheduler-1` fonctionne, désactiver l'ancien worker Supervisor. Si aucun autre programme n'est géré par Supervisor :

```sh
sudo supervisorctl stop symfony_scheduler
sudo mv /etc/supervisor/conf.d/symfony_scheduler.conf /etc/supervisor/symfony_scheduler.conf.disabled
sudo supervisorctl reread
sudo supervisorctl update
sudo systemctl disable --now supervisor
```

Le script de déploiement n'interagit plus avec Supervisor et ne demande donc plus son mot de passe sudo.

Les commandes Compose utilisent le nom de projet fixe `dashboard-prod` afin de conserver le même volume MySQL entre les déploiements :

```sh
docker compose -p dashboard-prod --env-file /dev/shm/.../.env.compose.prod -f compose.prod.yml ps
docker compose -p dashboard-prod --env-file /dev/shm/.../.env.compose.prod -f compose.prod.yml logs -f web php scheduler database
```
