#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
    echo "Usage: $0 NOM_ENV_VAR FICHIER" >&2
    echo "Affiche la valeur de la variable depuis le fichier indiqué de l'archive" >&2
    echo "de secrets. FICHIER est un chemin relatif à la racine de l'archive," >&2
    echo "par exemple .env ou .env.vue. La valeur est écrite sur stdout seule," >&2
    echo "praticable en substitution de commande." >&2
}

if [[ $# -ne 2 ]]; then
    usage
    exit 2
fi

name=$1
source_file=$2
archive=${SECRETS_ARCHIVE:-/home/deploy/secrets/secrets.tar.gz.gpg}

if [[ ! "$name" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "Nom de variable invalide: $name" >&2
    exit 2
fi
if [[ "$source_file" == /* || "$source_file" == *..* ]]; then
    echo "FICHIER doit être un chemin relatif sans '..': $source_file" >&2
    exit 1
fi
if [[ ! -f "$archive" ]]; then
    echo "Archive absente: $archive" >&2
    exit 1
fi

work_dir=$(mktemp -d /dev/shm/dashboard-secrets-read.XXXXXX)
cleanup() {
    rm -rf -- "$work_dir"
}
trap cleanup EXIT

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/prod-env-common.sh"
detect_archive_encryption "$archive"

decrypt_archive "$archive" "$work_dir/original.tar.gz"
mkdir -m 700 "$work_dir/unpacked"
tar -xzf "$work_dir/original.tar.gz" -C "$work_dir/unpacked" --no-same-owner

env_file="${work_dir}/unpacked/${source_file}"
if [[ ! -f "$env_file" ]]; then
    echo "Fichier introuvable dans l'archive: ${source_file}" >&2
    exit 1
fi

count=$(grep -c "^${name}=" "$env_file" || true)
if [[ "$count" -eq 0 ]]; then
    echo "Variable ${name} introuvable dans ${source_file}." >&2
    exit 1
fi
if [[ "$count" -gt 1 ]]; then
    echo "Plusieurs entrées ${name} trouvées dans ${source_file}." >&2
    exit 1
fi

value=$(grep "^${name}=" "$env_file" | head -n 1 | cut -d= -f2-)
# Strip surrounding quotes so the value can be passed to add/copy scripts as-is.
value=${value%\"}
value=${value#\"}
value=${value%\'}
value=${value#\'}

printf '%s\n' "$value"
