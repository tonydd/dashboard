#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
    echo "Usage: $0 NOM_ENV_VAR VAL_A_APPLIQUER" >&2
    echo "Modifie la variable dans tous les fichiers .env* où elle existe déjà." >&2
}

if [[ $# -ne 2 ]]; then
    usage
    exit 2
fi

name=$1
value=$2
archive=${SECRETS_ARCHIVE:-/home/deploy/secrets/secrets.tar.gz.gpg}

if [[ ! "$name" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "Nom de variable invalide: $name" >&2
    exit 2
fi
if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    echo "La valeur doit tenir sur une seule ligne." >&2
    exit 2
fi
if [[ ! -f "$archive" || ! -w "$(dirname -- "$archive")" ]]; then
    echo "Archive absente ou dossier non accessible en écriture: $archive" >&2
    exit 1
fi

work_dir=$(mktemp -d /dev/shm/dashboard-secrets-edit.XXXXXX)
staged_archive="${archive}.tmp.$$"
backup_archive="${archive}.bak.$(date +%Y%m%d%H%M%S)"

cleanup() {
    case "$work_dir" in
        /dev/shm/dashboard-secrets-edit.*)
            rm -rf -- "$work_dir"
            ;;
    esac
    rm -f -- "$staged_archive"
}
trap cleanup EXIT

packet_info=$(gpg --list-packets "$archive" 2>&1 || true)
mapfile -t recipients < <(
    printf '%s\n' "$packet_info" |
        sed -nE 's/.*keyid ([[:xdigit:]]+).*/\1/p' |
        sort -u
)
sym_cipher_id=$(printf '%s\n' "$packet_info" |
    sed -nE 's/.*symkey enc packet:.*cipher ([0-9]+).*/\1/p' |
    head -n 1)

if [[ ${#recipients[@]} -gt 0 ]]; then
    encryption_mode=public-key
    if [[ -n "${GPG_RECIPIENT:-}" ]]; then
        recipients=("$GPG_RECIPIENT")
    fi
elif [[ -n "$sym_cipher_id" ]]; then
    encryption_mode=symmetric
    case "$sym_cipher_id" in
        7) cipher=AES ;;
        8) cipher=AES192 ;;
        9) cipher=AES256 ;;
        *)
            echo "Algorithme de chiffrement symétrique non pris en charge: ${sym_cipher_id}." >&2
            exit 1
            ;;
    esac
elif [[ -n "${GPG_RECIPIENT:-}" ]]; then
    encryption_mode=public-key
    recipients=("$GPG_RECIPIENT")
else
    echo "Impossible de déterminer le mode de chiffrement GPG de l'archive." >&2
    exit 1
fi

passphrase=
if [[ "$encryption_mode" == symmetric ]]; then
    if [[ ! -r /dev/tty ]]; then
        echo "Un terminal interactif est requis pour saisir la phrase secrète GPG." >&2
        exit 1
    fi
    IFS= read -r -s -p "Phrase secrète GPG: " passphrase < /dev/tty
    printf '\n' > /dev/tty
    if [[ -z "$passphrase" ]]; then
        echo "La phrase secrète ne peut pas être vide." >&2
        exit 1
    fi
fi

decrypt_archive() {
    if [[ "$encryption_mode" == symmetric ]]; then
        printf '%s\n' "$passphrase" |
            gpg --batch --yes --pinentry-mode loopback --passphrase-fd 0 \
                --output "$2" --decrypt "$1"
    else
        gpg --output "$2" --decrypt "$1"
    fi
}

original_hash=$(sha256sum "$archive" | awk '{print $1}')
mkdir -m 700 "$work_dir/unpacked"
decrypt_archive "$archive" "$work_dir/original.tar.gz"
tar -xzf "$work_dir/original.tar.gz" -C "$work_dir/unpacked" --no-same-owner

mapfile -d '' env_files < <(find "$work_dir/unpacked" -type f -name '.env*' -print0)
matched_env_files=()
matched_env_paths=()
for env_file in "${env_files[@]}"; do
    env_count=$(grep -c "^${name}=" "$env_file" || true)
    if [[ "$env_count" -gt 1 ]]; then
        echo "Plusieurs entrées ${name} trouvées dans ${env_file}; archive laissée intacte." >&2
        exit 1
    fi
    if [[ "$env_count" -eq 1 ]]; then
        matched_env_files+=("$env_file")
        matched_env_paths+=("${env_file#"$work_dir/unpacked/"}")
    fi
done

if [[ ${#matched_env_files[@]} -eq 0 ]]; then
    echo "Variable ${name} absente de tous les fichiers .env*; archive laissée intacte." >&2
    exit 1
fi

escaped_value=$(printf '%s\n' "$value" | sed 's/[\\&|]/\\&/g')
for env_file in "${matched_env_files[@]}"; do
    sed "s|^${name}=.*$|${name}=${escaped_value}|" "$env_file" > "$work_dir/env.updated"
    mv -- "$work_dir/env.updated" "$env_file"
done

tar -czf "$work_dir/updated.tar.gz" -C "$work_dir/unpacked" .
gzip -t "$work_dir/updated.tar.gz"

if [[ "$encryption_mode" == symmetric ]]; then
    printf '%s\n' "$passphrase" |
        gpg --batch --yes --pinentry-mode loopback --passphrase-fd 0 \
            --output "$work_dir/updated.tar.gz.gpg" --symmetric \
            --cipher-algo "$cipher" "$work_dir/updated.tar.gz"
else
    gpg_args=(--output "$work_dir/updated.tar.gz.gpg" --encrypt)
    for recipient in "${recipients[@]}"; do
        gpg_args+=(--recipient "$recipient")
    done
    gpg "${gpg_args[@]}" "$work_dir/updated.tar.gz"
fi

decrypt_archive "$work_dir/updated.tar.gz.gpg" "$work_dir/verified.tar.gz"
mkdir -m 700 "$work_dir/verified"
tar -xzf "$work_dir/verified.tar.gz" -C "$work_dir/verified" --no-same-owner
for env_path in "${matched_env_paths[@]}"; do
    if ! grep -Fxq "${name}=${value}" "$work_dir/verified/$env_path"; then
        echo "Échec de la vérification dans ${env_path}; archive originale conservée." >&2
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

echo "Variable ${name} mise à jour dans ${#matched_env_files[@]} fichier(s) .env*."
echo "Sauvegarde chiffrée: ${backup_archive}"
echo "Relancez scripts/deploy-prod.sh pour reconstruire et déployer le frontend."
