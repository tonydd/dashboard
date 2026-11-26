# Dashboard

Dashboard domestique rendu côté serveur avec Symfony 7/Twig, accompagné de ses API métier.

## Etat local

- Le backend requiert PHP 8.4+/Symfony 7.4 sur le port `8000`.
- Docker Compose fournit aussi MySQL 8 sur le port hôte `33061`.
- Symfony a été validé dans le conteneur Docker local; Docker Compose fournit PHP, MySQL et les dépendances Symfony.

Le projet n’est pas prêt à être lancé en mode zéro configuration : il nécessite une configuration locale pour les clés/API externes et une base MySQL initialisée. Avec Docker installé, les services peuvent être démarrés puis initialisés en suivant le guide.

## Démarrage rapide

```sh
cp .env.example .env
docker compose up --build -d
docker compose exec php sh -c 'cd /var/www/html && php bin/console doctrine:migrations:migrate --no-interaction'
docker compose exec php sh -c 'cd /var/www/html && php bin/console app:dashboard:set-password'
```

- Dashboard/Twig : `http://localhost:8000/tablet`
- Diaporama : `http://localhost:8000/slideshow`
- API : `http://localhost:8000/api`
- Le mot de passe est demandé de façon interactive et son hash est conservé en base ; aucun mot de passe n'est configuré dans le front.
- Le compte tablette est en lecture seule ; les routes de création, modification et suppression sont réservées au rôle `ROLE_ADMIN`.
- Le délai de retour automatique tablette → diaporama se règle via `POST /api/dashboard/settings/slideshow-return` avec `{"seconds": 900}`, un compte `ROLE_ADMIN` et le jeton CSRF de `/api/session`. Valeurs autorisées : 60 à 86 400 secondes; défaut : 900 secondes.
- En production, expose l'interface et l'API uniquement en HTTPS pour protéger le cookie de session.

Avant le premier démarrage, créer `symfony/.env.local` avec l’URL MySQL Docker décrite dans [docs/LOCAL_SETUP.md](docs/LOCAL_SETUP.md).

## Documentation

- [Installation locale, Docker et base de données](docs/LOCAL_SETUP.md)
- [Déploiement Docker en production](docs/PRODUCTION_DEPLOYMENT.md)

## Commandes utiles

Backend :

```sh
cd symfony
composer install
php bin/console about
php bin/console doctrine:migrations:migrate
php bin/console debug:router
```
