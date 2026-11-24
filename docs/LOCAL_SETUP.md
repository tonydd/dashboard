# Lancer le dashboard localement

## Architecture

- `src/` : application Vue 3/Vite servie sur `http://localhost:5174`.
- `symfony/` : API Symfony 7.1/PHP servie sur `http://localhost:8000`.
- `db` : MySQL 8.0, publié sur `localhost:33061`.
- Docker Compose orchestre les trois services.

## Prérequis

Docker Engine avec la commande `docker compose` est recommandé. Pour un lancement sans Docker :

- Node.js 20+
- PHP 8.2+
- Composer 2
- MySQL 8

## Configuration

1. Copier la configuration du frontend :

   ```sh
   cp .env.example .env
   ```

2. Renseigner les clés externes optionnelles dans `.env`. Les widgets non configurés peuvent rester sur `TO_BE_DEFINED`, mais les endpoints API locaux doivent pointer vers `http://localhost:8000`.

3. Démarrer Docker Compose :

   ```sh
   docker compose up --build
   ```

4. Initialiser la base Symfony :

   ```sh
   docker compose exec php sh -c 'cd /var/www/html && php bin/console doctrine:migrations:migrate --no-interaction'
   ```

Le PHP est accessible sur `http://localhost:8000`; Vite est accessible sur `http://localhost:5174`.

## Base de données

La configuration Symfony locale doit utiliser le nom d’hôte Docker `db`, pas `127.0.0.1`.
Avec les valeurs Docker par défaut :

```dotenv
DATABASE_URL="mysql://root:root@db:3306/symfony?serverVersion=8.0.39&charset=utf8mb4"
```

Cette URL doit être placée dans `symfony/.env.local`, qui est ignoré par Git. Pour un accès MySQL depuis l’hôte, utiliser `127.0.0.1:33061`.

Les paramètres MySQL et le dossier de fonds d’écran peuvent être adaptés dans `.env`. Docker utilise `./symfony/var/backgrounds` par défaut.

## Pilotage du mode absence

Le pilotage du chauffe-eau via l’API exige un jeton Bearer distinct, gardé côté serveur. Générer un secret puis le définir dans le `.env` à la racine :

```sh
openssl rand -hex 32
```

```dotenv
THERMOR_API_TOKEN=<secret généré>
APP_TIMEZONE=Europe/Paris
```

Pour un lancement sans Docker, définis ces variables dans `symfony/.env.local` ou exporte-les dans l’environnement du processus PHP.

Recréer le service PHP pour charger ces variables :

```sh
docker compose up -d --force-recreate php
```

Activer et désactiver le mode absence :

```sh
export THERMOR_API_TOKEN='<même valeur que dans .env>'

curl -X POST http://localhost:8000/api/thermor/absence-mode \
  -H "Authorization: Bearer $THERMOR_API_TOKEN"

curl -X DELETE http://localhost:8000/api/thermor/absence-mode \
  -H "Authorization: Bearer $THERMOR_API_TOKEN"
```

L’activation suit la séquence Overkiz du chauffe-eau : mise à l’heure, début maintenant, fin dans un an, puis activation du mode programmé (`prog`). L’API répond `202 Accepted` lorsque les commandes sont acceptées par Overkiz. L’endpoint `GET /api/thermor` continue de fournir le dernier relevé stocké.

## Planning heures creuses / heures pleines

Le `ThermorScheduleHandler` applique le mode absence pendant les heures pleines et le désactive pendant les heures creuses. Il lit l’état actuel chez Overkiz et n’envoie pas de commande si le chauffe-eau est déjà dans l’état attendu. Le fuseau utilisé est `APP_TIMEZONE`. Le traitement est enregistré dans le `ScheduleProvider`, à raison d'une vérification par minute.

Au premier passage du handler, le planning est créé dans `current_stat_value` avec le type `thermor_schedule`. Le JSON contient les sept jours; chaque jour possède une liste de plages creuses. Le planning initial est 00:00–08:00 tous les jours :

```json
{
  "days": {
    "monday": [{"start": "00:00", "end": "08:00"}],
    "tuesday": [{"start": "00:00", "end": "08:00"}],
    "wednesday": [{"start": "00:00", "end": "08:00"}],
    "thursday": [{"start": "00:00", "end": "08:00"}],
    "friday": [{"start": "00:00", "end": "08:00"}],
    "saturday": [{"start": "00:00", "end": "08:00"}],
    "sunday": [{"start": "00:00", "end": "08:00"}]
  }
}
```

Le planning se modifie directement en base, sans endpoint API dédié. Pour ajouter une seconde plage creuse le lundi, par exemple 12:00–13:00 :

```sql
UPDATE current_stat_value
SET value = JSON_SET(
    value,
    '$.days.monday',
    JSON_ARRAY(
        JSON_OBJECT('start', '00:00', 'end', '08:00'),
        JSON_OBJECT('start', '12:00', 'end', '13:00')
    )
)
WHERE type = 'thermor_schedule';
```

Les heures sont au format 24 h `HH:MM`; les débuts sont inclus et les fins exclues. Une plage ne traverse pas minuit : il faut la répartir entre les deux jours. Les plages doivent être ordonnées et ne pas se chevaucher.

Les tâches planifiées de l'application partagent le transport Symfony Scheduler `scheduler_default`. Le cron externe doit donc démarrer/maintenir ce consommateur (et non appeler une commande dédiée au planning) :

```sh
docker compose exec -T php sh -c 'cd /var/www/html && php bin/console messenger:consume scheduler_default --time-limit=3600'
```

En production, si le consommateur est géré par Supervisor, redémarrez-le après chaque déploiement ou `cache:clear`. Le cache applicatif et le cache Doctrine sont stockés dans `var/cache`; les deux doivent être accessibles en écriture par l'utilisateur du worker. Si le déploiement et le worker utilisent des utilisateurs différents, configurez un groupe ou des ACL communs. Sinon, le worker peut continuer à tourner avec un chemin de cache supprimé ou ne pas pouvoir enregistrer les jetons API et les résultats Doctrine.

Pour un lancement sans Docker, depuis `symfony/`, utilisez de même :

```sh
php bin/console messenger:consume scheduler_default --time-limit=3600
```

## Lancement sans Docker

Frontend :

```sh
npm install
npm run dev
```

Backend :

```sh
cd symfony
composer install
php bin/console doctrine:migrations:migrate
php -S 0.0.0.0:8000 -t public
```

Dans ce mode, `DATABASE_URL` doit pointer vers le MySQL local.

## Variables personnelles

Ne jamais committer `.env`, `symfony/.env` ni `symfony/.env.local`. Les secrets éventuels présents uniquement dans ces fichiers locaux doivent être régénérés avant toute publication du repository.

Les intégrations suivantes sont optionnelles ou personnelles : Spotify, Google Calendar, OpenWeatherMap, Tuya/Solar, Atlantic/Overkiz, Mistral et les dossiers de fonds d’écran.
