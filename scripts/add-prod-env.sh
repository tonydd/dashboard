#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
    echo "Usage: $0 NOM_ENV_VAR VAL_A_APPLIQUER FICHIER" >&2
    echo "Ajoute (ou met à jour) la variable dans le fichier indiqué de l'archive de" >&2
    echo "secrets, puis réencrypte. FICHIER est le chemin relatif à la racine de" >&2
    echo "l'archive, par exemple .env.compose.prod." >&2
}

if [[ $# -ne 3 ]]; then
    usage
    exit 2
fi

name=$1
value=$2
target_file=$3
archive=${SECRETS_ARCHIVE:-/home/deploy/secrets/secrets.tar.gz.gpg}

if [[ ! "$name" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "Nom de variable invalide: $name" >&2
    exit 2
fi
if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    echo "La valeur doit tenir sur une seule ligne." >&2
    exit 2
fi
if [[ "$target_file" == /* || "$target_file" == *..* ]]; then
    echo "FICHIER doit être un chemin relatif sans '..': $target_file" >&2
    exit 2
fi
if [[ ! -f "$archive" || ! -w "$(dirname -- "$archive")" ]]; then
    echo "Archive absente ou dossier non accessible en écriture: $archive" >&2
    exit 1
fi

work_dir=$(mktemp -d /dev/shm/dashboard-secrets-add.XXXXXX)
staged_archive="${archive}.tmp.$$"
backup_archive="${archive}.bak.$(date +%Y%m%d%H%M%S%3N)"

cleanup() {
    case "$work_dir" in
        /dev/shm/dashboard-secrets-add.*)
            rm -rf -- "$work_dir"
            ;;
    esac
    rm -f -- "$staged_archive"
}
trap cleanup EXIT

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/prod-env-common.sh"
detect_archive_encryption "$archive"

original_hash=$(sha256sum "$archive" | awk '{print $1}')
mkdir -m 700 "$work_dir/unpacked"
decrypt_archive "$archive" "$work_dir/original.tar.gz"
tar -xzf "$work_dir/original.tar.gz" -C "$work_dir/unpacked" --no-same-owner

env_file="${work_dir}/unpacked/${target_file}"
if [[ ! -f "$env_file" ]]; then
    echo "Fichier introuvable dans l'archive: ${target_file}" >&2
    echo "Contenu de l'archive :" >&2
    find "$work_dir/unpacked" -type f -printf '  %P\n' >&2
    exit 1
fi

env_count=$(grep -c "^${name}=" "$env_file" || true)
if [[ "$env_count" -gt 1 ]]; then
    echo "Plusieurs entrées ${name} trouvées dans ${target_file}; archive laissée intacte." >&2
    exit 1
fi

escaped_value=$(printf '%s\n' "$value" | sed 's/[\\&|]/\\&/g')
if [[ "$env_count" -eq 1 ]]; then
    sed "s|^${name}=.*$|${name}=${escaped_value}|" "$env_file" > "$work_dir/env.updated"
    mv -- "$work_dir/env.updated" "$env_file"
else
    [[ -n "$(tail -c 1 "$env_file")" ]] && printf '\n' >> "$env_file"
    printf '%s=%s\n' "$name" "$value" >> "$env_file"
fi

# Informational only: the caller explicitly targeted one file.
for other_env in $(find "$work_dir/unpacked" -type f -name '.env*' ! -path "$env_file"); do
    if grep -q "^${name}=" "$other_env"; then
        echo "Attention : ${name} existe aussi dans ${other_env#"$work_dir/unpacked/"} (laissé inchangé)."
    fi
done

tar -czf "$work_dir/updated.tar.gz" -C "$work_dir/unpacked" .
gzip -t "$work_dir/updated.tar.gz"

encrypt_archive "$work_dir/updated.tar.gz" "$work_dir/updated.tar.gz.gpg"

decrypt_archive "$work_dir/updated.tar.gz.gpg" "$work_dir/verified.tar.gz"
mkdir -m 700 "$work_dir/verified"
tar -xzf "$work_dir/verified.tar.gz" -C "$work_dir/verified" --no-same-owner
if ! grep -Fxq "${name}=${value}" "$work_dir/verified/${target_file}"; then
    echo "Échec de la vérification dans ${target_file}; archive originale conservée." >&2
    exit 1
fi

current_hash=$(sha256sum "$archive" | awk '{print $1}')
if [[ "$current_hash" != "$original_hash" ]]; then
    echo "L'archive a changé pendant le traitement; remplacement annulé." >&2
    exit 1
fi
if [[ -e "$backup_archive" || -e "$staged_archive" ]]; then
    echo "Fichier de sauvegarde ou temporaire déjà présent; remplacement annulé." >&2
    exit 1
fi

cp -p -- "$archive" "$backup_archive"
install -m 600 -- "$work_dir/updated.tar.gz.gpg" "$staged_archive"
mv -fT -- "$staged_archive" "$archive"

echo "Variable ${name} appliquée dans ${target_file} (mode ${ENCRYPTION_MODE})."
echo "Sauvegarde chiffrée: ${backup_archive}"
echo "Relancez scripts/deploy-prod.sh pour reconstruire et déployer."
