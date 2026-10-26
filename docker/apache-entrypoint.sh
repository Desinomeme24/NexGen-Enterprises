#!/bin/sh
set -eu

PORT="${PORT:-80}"
case "$PORT" in
    ''|*[!0-9]*)
        echo "Invalid PORT value '$PORT'; expected an integer from 1 to 65535." >&2
        exit 1
        ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "Invalid PORT value '$PORT'; expected an integer from 1 to 65535." >&2
    exit 1
fi

UPLOAD_DIR="${NEXGEN_PUBLIC_UPLOAD_DIR:-/data/nexgen-private/public-uploads}"
PRIVATE_UPLOAD_DIR="${NEXGEN_PRIVATE_UPLOAD_DIR:-/data/nexgen-private}"
QUARANTINE_DIR="${NEXGEN_UPLOAD_QUARANTINE_DIR:-/tmp/nexgen-upload-quarantine}"

mkdir -p \
    "${UPLOAD_DIR}/products" \
    "${PRIVATE_UPLOAD_DIR}/valid_ids" \
    "${PRIVATE_UPLOAD_DIR}/business_permits" \
    "$QUARANTINE_DIR"
chown -R www-data:www-data "$UPLOAD_DIR" "$PRIVATE_UPLOAD_DIR" "$QUARANTINE_DIR"
chmod 0750 "$PRIVATE_UPLOAD_DIR" "${PRIVATE_UPLOAD_DIR}/valid_ids" "${PRIVATE_UPLOAD_DIR}/business_permits"
chmod 0700 "$QUARANTINE_DIR"

rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
a2enmod mpm_prefork
sed -ri "s/^Listen[[:space:]]+80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
echo "Starting Apache on PORT=${PORT}"
apache2ctl configtest
exec apache2-foreground
