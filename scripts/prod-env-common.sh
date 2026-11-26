# Shared GPG handling for scripts manipulating the encrypted production
# secrets archive. Scripts source this file, then call:
#   detect_archive_encryption <archive>  - sets ENCRYPTION_MODE (public-key or
#       symmetric), RECIPIENTS[] and CIPHER; prompts for the passphrase on
#       /dev/tty in symmetric mode
#   decrypt_archive <archive> <output>
#   encrypt_archive <tar.gz> <output.gpg>

detect_archive_encryption() {
    local archive=$1 packet_info sym_cipher_id
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
        ENCRYPTION_MODE=public-key
        RECIPIENTS=("${recipients[@]}")
        if [[ -n "${GPG_RECIPIENT:-}" ]]; then
            RECIPIENTS=("$GPG_RECIPIENT")
        fi
    elif [[ -n "$sym_cipher_id" ]]; then
        ENCRYPTION_MODE=symmetric
        case "$sym_cipher_id" in
            7) CIPHER=AES ;;
            8) CIPHER=AES192 ;;
            9) CIPHER=AES256 ;;
            *)
                echo "Algorithme de chiffrement symétrique non pris en charge: ${sym_cipher_id}." >&2
                exit 1
                ;;
        esac
        if [[ ! -r /dev/tty ]]; then
            echo "Un terminal interactif est requis pour saisir la phrase secrète GPG." >&2
            exit 1
        fi
        IFS= read -r -s -p "Phrase secrète GPG: " PASSPHRASE < /dev/tty
        printf '\n' > /dev/tty
        if [[ -z "$PASSPHRASE" ]]; then
            echo "La phrase secrète ne peut pas être vide." >&2
            exit 1
        fi
    elif [[ -n "${GPG_RECIPIENT:-}" ]]; then
        ENCRYPTION_MODE=public-key
        RECIPIENTS=("$GPG_RECIPIENT")
    else
        echo "Impossible de déterminer le mode de chiffrement GPG de l'archive." >&2
        exit 1
    fi
}

decrypt_archive() {
    if [[ "$ENCRYPTION_MODE" == symmetric ]]; then
        printf '%s\n' "$PASSPHRASE" |
            gpg --batch --yes --pinentry-mode loopback --passphrase-fd 0 \
                --output "$2" --decrypt "$1"
    else
        gpg --output "$2" --decrypt "$1"
    fi
}

encrypt_archive() {
    if [[ "$ENCRYPTION_MODE" == symmetric ]]; then
        printf '%s\n' "$PASSPHRASE" |
            gpg --batch --yes --pinentry-mode loopback --passphrase-fd 0 \
                --output "$2" --symmetric \
                --cipher-algo "$CIPHER" "$1"
    else
        local gpg_args=(--output "$2" --encrypt)
        local recipient
        for recipient in "${RECIPIENTS[@]}"; do
            gpg_args+=(--recipient "$recipient")
        done
        gpg "${gpg_args[@]}" "$1"
    fi
}
