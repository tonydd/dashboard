# Déploiement Docker en production

La production conserve Apache sur l'hôte pour les certificats TLS et les deux noms de domaine actuels. Docker fournit l'interface, l'API Symfony en `APP_ENV=prod`, MySQL, et un worker Scheduler indépendant. L'hôte n'a plus besoin de PHP, Composer, Node.js, MySQL ni Supervisor pour ces composants. L'image Symfony utilise PHP 8.4, conformément à la version minimale requise par le `composer.lock`, et Composer s'exécute uniquement pendant le build de l'image.

Les services exposent uniquement des ports loopback :

- `web` est un proxy Caddy léger vers Symfony sur `127.0.0.1:9101` par défaut.
- `php` sert Symfony/Apache sur `127.0.0.1:9100` par défaut.
- `scheduler` exécute `messenger:consume scheduler_default` et redémarre automatiquement.
- `database` utilise un volume Docker persistant et n'expose aucun port à l'hôte.

## Apache hôte

Activer les modules `proxy` et `proxy_http`. Dans le VirtualHost frontend, remplacer le `DocumentRoot` et les anciennes règles de fallback par ces relais :

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

Le navigateur accède uniquement à Symfony via Caddy/Apache. Les intégrations de données utilisent les endpoints relatifs `/api/...` et les secrets restent dans l'environnement Symfony. Sans images `.jpg`, `/api/background` répond `404`.

## Configuration et secrets

Le script de déploiement s'attend à trouver dans l'archive GPG, extraite sous `/dev/shm` :

- `.env` : variables Symfony et secrets backend existants.
- `.env.compose.prod` : paramètres MySQL Docker et, facultativement, le dossier hôte des fonds d'écran (`BACKGROUND_HOST_PATH`), basé sur `.env.compose.prod.example`.
- `database.sql.gz` : export initial de la base MySQL existante, requis uniquement lors du premier déploiement dans un volume vide.

Ces fichiers doivent être à la racine de l'archive, pas dans un sous-dossier. `.env.compose.prod.example` et `.env.example` sont des modèles du dépôt, pas des fichiers à inclure dans l'archive. Le script exige `.env` et `.env.compose.prod`, ainsi que le dump uniquement si le volume MySQL Docker est vide.

La configuration serveur de la météo et du nom de station carburant se place dans le `.env` Symfony chiffré sous `OPENWEATHERMAP_API_URL`, `OPENWEATHERMAP_API_KEY`, `OPENWEATHERMAP_LAT`, `OPENWEATHERMAP_LON`, `OPENWEATHERMAP_UNITS`, `OPENWEATHERMAP_LANG` et `FUEL_API_STATION_NAME`. La clé météo reste dans l'environnement du conteneur PHP et n'est jamais intégrée aux assets ni renvoyée au navigateur. Pour le slideshow, renseigner au besoin `BACKGROUND_TOKEN` dans le même fichier : il est ajouté à l'URL de l'image distante et sera donc visible dans la requête du navigateur. Pour faciliter la bascule, les anciens fichiers `.env.production.vue` ou `.env.vue` de l'archive sont facultatifs : le script en passe un au conteneur PHP seulement si présent, et Symfony accepte les anciennes clés `VITE_OPENWEATHERMAP_*`, `VITE_FUEL_API_STATION_NAME` et `VITE_BACKGROUND_TOKEN` comme repli. Les nouveaux noms sans `VITE_` sont prioritaires.

`THERMOR_API_TOKEN` est facultatif : sans cette variable, la lecture de l'état du chauffe-eau reste disponible, mais les commandes de pilotage répondent `503` jusqu'à sa configuration.

Pour préparer l'archive, partir de l'archive actuelle, la déchiffrer dans un dossier temporaire sous `/dev/shm`, puis y ajouter ou remplacer `.env.compose.prod`. Copier les valeurs du modèle `.env.compose.prod.example` et renseigner les identifiants MySQL et deux ports loopback libres (`9100` pour l'API et `9101` pour le frontend par défaut). `BACKGROUND_HOST_PATH` est facultatif; s'il est omis, Compose utilise `./symfony/var/backgrounds` dans le checkout de déploiement. Générer des mots de passe MySQL distincts, de préférence hexadécimaux, et ne jamais committer le fichier réel. Recréer ensuite le `tar.gz`, le chiffrer avec le même mécanisme GPG que l'archive actuelle, puis vérifier que le script `gpg --decrypt ... | tar xz` peut le lire. Ne remplacer l'archive de production qu'après cette vérification.

Le backend utilise toujours `BACKGROUND_PATH` pour servir le fond d'écran quotidien via `/api/background`. Si aucun fichier `.jpg` n'est présent dans le dossier monté, le déploiement ne bloque plus, mais cette route ne pourra pas fournir d'image.

Pour créer le dump initial depuis la base MySQL actuelle, exécuter par exemple `mysqldump -u UTILISATEUR -p --single-transaction --routines --triggers --events --databases NOM_BASE | gzip > /dev/shm/database.sql.gz`, puis vérifier le résultat avec `gzip -t /dev/shm/database.sql.gz`. Le dump contient les données de production : le garder temporairement en RAM et le chiffrer dans l'archive. Conserver également une sauvegarde indépendante avant la bascule. Les redéploiements suivants n'ont pas besoin de `database.sql.gz` tant que le volume Docker contient déjà les tables.

Le fichier Symfony et les identifiants MySQL restent dans `/dev/shm`; le `.env` Symfony est injecté au runtime. Aucun secret n'est incorporé aux assets Twig/JavaScript. Les mots de passe MySQL utilisés dans `DATABASE_URL` doivent être URL-safe; des secrets hexadécimaux conviennent.

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
