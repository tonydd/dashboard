#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO="https://github.com/tonydd/dashboard.git"
# Branch to deploy; pass the branch name as the first argument (default: main).
BRANCH="${1:-main}"
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
CLONE_DIR="$(mktemp -d /tmp/dashboard-deploy-XXXXXX)"
SECRETS_DIR="$(mktemp -d /dev/shm/dashboard-secrets-XXXXXX)"
COMPOSE_PROJECT="dashboard-prod"
COMPOSE_FILE="${CLONE_DIR}/compose.prod.yml"
COMPOSE_ENV="${SECRETS_DIR}/.env.compose.prod"

cleanup() {
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
    .env.compose.prod
do
    if [[ ! -f "${SECRETS_DIR}/${required_file}" ]]; then
        echo "Fichier requis absent de l'archive de secrets : ${required_file}" >&2
        exit 1
    fi
done

# Docker Compose interpolates $ in env_file values ($$ -> literal $), so a
# value containing a literal $ must be escaped once before compose reads it.
# The archive keeps the true values; escape the extracted copies in place.
# shellcheck disable=SC2016
sed -i 's/\$/\$\$/g' "${SECRETS_DIR}/.env"

export SYMFONY_ENV_FILE="${SECRETS_DIR}/.env"
if [[ -f "${SECRETS_DIR}/.env.production.vue" ]]; then
    DASHBOARD_ENV_FILE="${SECRETS_DIR}/.env.production.vue"
elif [[ -f "${SECRETS_DIR}/.env.vue" ]]; then
    DASHBOARD_ENV_FILE="${SECRETS_DIR}/.env.vue"
fi
if [[ -n "${DASHBOARD_ENV_FILE:-}" ]]; then
    # shellcheck disable=SC2016
    sed -i 's/\$/\$\$/g' "${DASHBOARD_ENV_FILE}"
    export DASHBOARD_ENV_FILE
fi

echo "=== Clone du dépôt (branche ${BRANCH}) ==="
if ! git ls-remote --exit-code --heads "$REPO" "refs/heads/${BRANCH}" >/dev/null 2>&1; then
    echo "Branche introuvable sur ${REPO} : ${BRANCH}" >&2
    exit 1
fi
git clone --depth 1 --branch "$BRANCH" --single-branch "$REPO" "$CLONE_DIR"
mkdir -p "${CLONE_DIR}/symfony/var/backgrounds"

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

echo "=== Démarrage des services applicatifs ==="
compose up -d php web scheduler webcam-ws
compose exec --user www-data --no-TTY php php bin/console about --env=prod

echo
echo "Déploiement Docker terminé : ${TIMESTAMP} (branche ${BRANCH})"
echo "Configurer Apache vers les ports PHP et frontend indiqués dans .env.compose.prod."
