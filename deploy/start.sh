#!/bin/sh
set -eu

cd /var/www/html
export PORT="${PORT:-10000}"
case "$PORT" in
    ''|*[!0-9]*) echo 'PORT debe ser un numero de puerto valido.' >&2; exit 1 ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo 'PORT debe estar entre 1 y 65535.' >&2
    exit 1
fi

# Si falla la base o la primera cuenta, no se publica una aplicación incompleta.
php bin/preparar_servidor.php
exec apache2-foreground
