#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO="https://github.com/tonydd/dashboard.git"
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
CLONE_DIR="$(mktemp -d /tmp/dashboard-deploy-XXXXXX)"
SECRETS_DIR="$(mktemp -d /dev/shm/dashboard-secrets-XXXXXX)"
COMPOSE_PROJECT="dashboard-prod"
COMPOSE_FILE="${CLONE_DIR}/compose.prod.yml"
COMPOSE_ENV="${SECRETS_DIR}/.env.compose.prod"
SUPERVISOR_WAS_RUNNING=false
DEPLOY_SUCCEEDED=false

cleanup() {
    if [[ "$SUPERVISOR_WAS_RUNNING" == true && "$DEPLOY_SUCCEEDED" != true ]]; then
        compose stop scheduler >/dev/null 2>&1 || true
        sudo /usr/bin/supervisorctl start symfony_scheduler >/dev/null 2>&1 || true
    fi
    echo "Nettoyage des secrets et fichiers temporaires..."
    rm -rf -- "$SECRETS_DIR" "$CLONE_DIR"
}
trap cleanup EXIT

compose() {
    docker compose \
        --project-name "$COMPOSE_PROJECT" \
        --env-file "$COMPOSE_ENV" \
        --file "$COMPOSE_FILE" \
        "$@"
}

echo "=== Déchiffrement des secrets en RAM ==="
gpg --decrypt /home/deploy/secrets/secrets.tar.gz.gpg \
    | tar xz -C "$SECRETS_DIR"

for required_file in \
    .env \
    .env.vue \
    .env.production.vue \
    .env.compose.prod
do
    if [[ ! -f "${SECRETS_DIR}/${required_file}" ]]; then
        echo "Fichier requis absent de l'archive de secrets : ${required_file}" >&2
        exit 1
    fi
done

echo "=== Préparation des fichiers de build frontend en RAM ==="
awk '!/^[[:space:]]*(export[[:space:]]+)?VITE_SPOTIFY_CLIENT_SECRET[[:space:]]*=/' \
    "${SECRETS_DIR}/.env.vue" > "${SECRETS_DIR}/.env.vue.safe"
awk '!/^[[:space:]]*(export[[:space:]]+)?VITE_SPOTIFY_CLIENT_SECRET[[:space:]]*=/' \
    "${SECRETS_DIR}/.env.production.vue" > "${SECRETS_DIR}/.env.production.vue.safe"

export SYMFONY_ENV_FILE="${SECRETS_DIR}/.env"

echo "=== Clone du dépôt ==="
git clone --depth 1 "$REPO" "$CLONE_DIR"

echo "=== Préparation des paramètres frontend publics ==="
mkdir -p "${CLONE_DIR}/Docker/frontend/build-env"
cp "${SECRETS_DIR}/.env.vue.safe" "${CLONE_DIR}/Docker/frontend/build-env/.env"
cp "${SECRETS_DIR}/.env.production.vue.safe" "${CLONE_DIR}/Docker/frontend/build-env/.env.production"
chmod 600 \
    "${CLONE_DIR}/Docker/frontend/build-env/.env" \
    "${CLONE_DIR}/Docker/frontend/build-env/.env.production"

echo "=== Validation et build des images de production ==="
compose config --quiet
compose build

echo "=== Démarrage de MySQL ==="
compose up -d database
db_container="$(compose ps -q database)"
if [[ -z "$db_container" ]]; then
    echo "Le conteneur MySQL ne s'est pas créé." >&2
    exit 1
fi

db_ready=false
for _ in $(seq 1 60); do
    db_status="$(docker inspect --format '{{.State.Health.Status}}' "$db_container" 2>/dev/null || true)"
    if [[ "$db_status" == "healthy" ]]; then
        db_ready=true
        break
    fi
    if [[ "$db_status" == "unhealthy" ]]; then
        break
    fi
    sleep 2
done
if [[ "$db_ready" != true ]]; then
    echo "MySQL n'est pas devenu healthy; consulter les logs du service database." >&2
    compose logs --tail=100 database
    exit 1
fi

table_count="$(
    compose exec --no-TTY database sh -c \
        'mysql --batch --skip-column-names -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" "$MYSQL_DATABASE"'
)"
if [[ "$table_count" == "0" ]]; then
    if [[ ! -f "${SECRETS_DIR}/database.sql.gz" ]]; then
        echo "La base Docker est vide. Fournir database.sql.gz dans l'archive pour importer les données existantes." >&2
        exit 1
    fi
    gzip -t "${SECRETS_DIR}/database.sql.gz"
    echo "=== Import initial de la base MySQL ==="
    gzip -dc "${SECRETS_DIR}/database.sql.gz" \
        | compose exec --no-TTY database sh -c \
            'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
fi

echo "=== Migrations Doctrine ==="
compose run --rm --no-deps --user www-data php \
    php bin/console doctrine:migrations:migrate \
    --no-interaction --allow-no-migration --env=prod

if [[ -x /usr/bin/supervisorctl ]] \
    && sudo /usr/bin/supervisorctl status symfony_scheduler 2>/dev/null | grep -q RUNNING
then
    sudo /usr/bin/supervisorctl stop symfony_scheduler
    SUPERVISOR_WAS_RUNNING=true
fi

echo "=== Démarrage des services applicatifs ==="
compose up -d php web scheduler
compose exec --user www-data --no-TTY php php bin/console about --env=prod
DEPLOY_SUCCEEDED=true

echo
echo "Déploiement Docker terminé : ${TIMESTAMP}"
echo "Configurer Apache vers les ports PHP et frontend indiqués dans .env.compose.prod."
