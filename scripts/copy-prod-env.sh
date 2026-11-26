#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
    echo "Usage: $0 FICHIER_SOURCE FICHIER_CIBLE VAR[=VAR_CIBLE] [VAR[=VAR_CIBLE]...]" >&2
    echo "Copie des variables d'un membre à l'autre de l'archive de secrets (déchiffre" >&2
    echo "et réencrypte une seule fois, une seule phrase secrète). Par défaut la" >&2
    echo "variable garde son nom; VAR_CIBLE permet de le changer, par exemple" >&2
    echo "VITE_OPENWEATHERMAP_API_KEY=OPENWEATHERMAP_API_KEY." >&2
}

if [[ $# -lt 3 ]]; then
    usage
    exit 2
fi

source_file=$1
target_file=$2
shift 2
archive=${SECRETS_ARCHIVE:-/home/deploy/secrets/secrets.tar.gz.gpg}

for file in "$source_file" "$target_file"; do
    if [[ "$file" == /* || "$file" == *..* ]]; then
        echo "Les fichiers doivent être des chemins relatifs sans '..': $file" >&2
        exit 1
    fi
done

declare -a sources=() targets=()
for mapping in "$@"; do
    if [[ "$mapping" == *=* ]]; then
        src=${mapping%%=*}
        dst=${mapping#*=}
    else
        src=$mapping
        dst=$mapping
    fi
    if [[ ! "$src" =~ ^[A-Za-z_][A-Za-z0-9_]*$ || ! "$dst" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
        echo "Nom de variable invalide: $mapping" >&2
        exit 2
    fi
    sources+=("$src")
    targets+=("$dst")
done

if [[ ! -f "$archive" || ! -w "$(dirname -- "$archive")" ]]; then
    echo "Archive absente ou dossier non accessible en écriture: $archive" >&2
    exit 1
fi

work_dir=$(mktemp -d /dev/shm/dashboard-secrets-copy.XXXXXX)
staged_archive="${archive}.tmp.$$"
backup_archive="${archive}.bak.$(date +%Y%m%d%H%M%S%3N)"

cleanup() {
    rm -rf -- "$work_dir"
    rm -f -- "$staged_archive"
}
trap cleanup EXIT

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/prod-env-common.sh"
detect_archive_encryption "$archive"

original_hash=$(sha256sum "$archive" | awk '{print $1}')
mkdir -m 700 "$work_dir/unpacked"
decrypt_archive "$archive" "$work_dir/original.tar.gz"
tar -xzf "$work_dir/original.tar.gz" -C "$work_dir/unpacked" --no-same-owner

src_env="${work_dir}/unpacked/${source_file}"
tgt_env="${work_dir}/unpacked/${target_file}"
for env_file in "$src_env" "$tgt_env"; do
    if [[ ! -f "$env_file" ]]; then
        echo "Fichier introuvable dans l'archive: ${env_file#"$work_dir/unpacked/"}" >&2
        echo "Contenu de l'archive :" >&2
        find "$work_dir/unpacked" -type f -printf '  %P\n' >&2
        exit 1
    fi
done

copied=0
declare -A copied_indexes=()
for index in "${!sources[@]}"; do
    src=${sources[$index]}
    dst=${targets[$index]}

    count=$(grep -c "^${src}=" "$src_env" || true)
    if [[ "$count" -gt 1 ]]; then
        echo "Plusieurs entrées ${src} dans ${source_file}; archive laissée intacte." >&2
        exit 1
    fi
    if [[ "$count" -eq 0 ]]; then
        echo "Attention : ${src} absent de ${source_file}, ignoré."
        continue
    fi
    value=$(grep "^${src}=" "$src_env" | head -n 1 | cut -d= -f2-)
    # Strip surrounding quotes so the target file holds the raw value.
    value=${value%\"}
    value=${value#\"}
    value=${value%\'}
    value=${value#\'}

    escaped_value=$(printf '%s\n' "$value" | sed 's/[\\&|]/\\&/g')
    dst_count=$(grep -c "^${dst}=" "$tgt_env" || true)
    if [[ "$dst_count" -gt 1 ]]; then
        echo "Plusieurs entrées ${dst} dans ${target_file}; archive laissée intacte." >&2
        exit 1
    fi
    if [[ "$dst_count" -eq 1 ]]; then
        sed "s|^${dst}=.*$|${dst}=${escaped_value}|" "$tgt_env" > "$work_dir/env.updated"
        mv -- "$work_dir/env.updated" "$tgt_env"
    else
        [[ -n "$(tail -c 1 "$tgt_env")" ]] && printf '\n' >> "$tgt_env"
        printf '%s=%s\n' "$dst" "$value" >> "$tgt_env"
    fi
    copied=$((copied + 1))
    copied_indexes[$index]=1
    echo "Copié ${src} -> ${dst} (${target_file})."
done

if [[ "$copied" -eq 0 ]]; then
    echo "Aucune variable copiée; archive laissée intacte." >&2
    exit 1
fi

tar -czf "$work_dir/updated.tar.gz" -C "$work_dir/unpacked" .
gzip -t "$work_dir/updated.tar.gz"

encrypt_archive "$work_dir/updated.tar.gz" "$work_dir/updated.tar.gz.gpg"

# Verify the reencrypted archive round-trips before replacing the original.
decrypt_archive "$work_dir/updated.tar.gz.gpg" "$work_dir/verified.tar.gz"
mkdir -m 700 "$work_dir/verified"
tar -xzf "$work_dir/verified.tar.gz" -C "$work_dir/verified" --no-same-owner
for index in "${!targets[@]}"; do
    [[ "${copied_indexes[$index]:-}" == 1 ]] || continue
    src=${sources[$index]}
    dst=${targets[$index]}
    if ! grep -q "^${src}=" "$work_dir/verified/${source_file}" \
        || ! grep -q "^${dst}=" "$work_dir/verified/${target_file}"; then
        echo "Échec de la vérification; archive originale conservée." >&2
        exit 1
    fi
done

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

echo "Terminé: ${copied} variable(s) copiée(s) de ${source_file} vers ${target_file} (mode ${ENCRYPTION_MODE})."
echo "Sauvegarde chiffrée: ${backup_archive}"
echo "Relancez scripts/deploy-prod.sh pour reconstruire et déployer."
