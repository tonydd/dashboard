# Dashboard

Dashboard domestique composé d’une interface Vue 3/Vite et d’une API Symfony 7.

## Etat local

- Le frontend est prévu pour Node.js 20/Vite sur le port `5174`.
- Le backend est prévu pour PHP 8.2+/Symfony sur le port `8000`.
- Docker Compose fournit aussi MySQL 8 sur le port hôte `33061`.
- Les dépendances frontend et Symfony sont déjà présentes dans ce clone, mais les runtimes Docker/Node/PHP ne sont pas disponibles dans l’environnement actuel, donc aucun démarrage vérifié n’a pu être exécuté ici.

Le projet n’est pas prêt à être lancé en mode zéro configuration : il nécessite une configuration locale pour les clés/API externes et une base MySQL initialisée. Avec Docker installé, les services peuvent être démarrés puis initialisés en suivant le guide.

## Démarrage rapide

```sh
cp .env.example .env
docker compose up --build
docker compose exec php sh -c 'cd /var/www/html && php bin/console doctrine:migrations:migrate --no-interaction'
```

- Interface : `http://localhost:5174`
- API : `http://localhost:8000`

Avant le premier démarrage, créer `symfony/.env.local` avec l’URL MySQL Docker décrite dans [docs/LOCAL_SETUP.md](docs/LOCAL_SETUP.md).

## Documentation

- [Installation locale, Docker et base de données](docs/LOCAL_SETUP.md)

## Commandes utiles

Frontend :

```sh
npm install
npm run dev
npm run build
```

Backend :

```sh
cd symfony
composer install
php bin/console about
php bin/console doctrine:migrations:migrate
php bin/console debug:router
```
