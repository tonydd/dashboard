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
- Caméra : `http://localhost:8000/camera`
- API : `http://localhost:8000/api`
- Le mot de passe est demandé de façon interactive et son hash est conservé en base ; aucun mot de passe n'est configuré dans le front.
- Le compte tablette est en lecture seule ; les routes de création, modification et suppression sont réservées au rôle `ROLE_ADMIN`.
- Le délai de retour automatique tablette → diaporama se règle via `POST /api/dashboard/settings/slideshow-return` avec `{"seconds": 900}`, un compte `ROLE_ADMIN` et le jeton CSRF de `/api/session`. Valeurs autorisées : 60 à 86 400 secondes; défaut : 900 secondes.
- En production, expose l'interface et l'API uniquement en HTTPS pour protéger le cookie de session.

## Caméra de la tablette (WebRTC)

L'écran tablette expose sa caméra WebRTC via un serveur de signalisation WebSocket dédié :

1. `Caméra` sur `/tablet` demande l'accès caméra (`getUserMedia`, sans audio) puis ouvre la connexion de signalisation ; un aperçu local s'affiche tant que le bouton est `ON`.
2. La page `/camera`, protégée par le même mot de passe, affiche le flux depuis n'importe quel appareil connecté.
3. Le serveur de signalisation (`php bin/console app:camera:ws`, service Docker `webcam-ws`) relaie uniquement les offres/réponses SDP et les candidats ICE ; le flux vidéo circule en peer-to-peer, chiffré par WebRTC.

Authentification WebSocket : `/api/camera/token` (session + CSRF, rôle `ROLE_VIEWER`) délivre un jeton HMAC courte durée qui autorise une connexion de signalisation en tant que `publisher` (tablette) ou `viewer` (page caméra). Une seule tablette peut publier à la fois.

Développement : le service `webcam-ws` écoute sur `ws://localhost:8081` (option `CAMERA_WS_URL` du `.env` pour le forcer). En production, la route `/camera-ws` est routée par Caddy vers ce service ; le reverse proxy externe doit donc transmettre l'upgrade WebSocket (`Upgrade`/`Connection`) sans tampon de réponse pour ce chemin.
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
