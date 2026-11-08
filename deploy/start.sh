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
# El proceso de tareas mantiene recordatorios e inactividad aun sin visitas web.
# Si el proceso de tareas termina por un error, se reinicia tras 5 segundos (|| true evita que set -e cierre el bucle).
while true; do php bin/procesar_tareas.php || true; echo "tareas reiniciadas" >&2; sleep 5; done &
tasks_pid=$!
trap 'kill "$tasks_pid" 2>/dev/null || true' EXIT INT TERM
apache2-foreground &
web_pid=$!
trap 'kill "$tasks_pid" "$web_pid" 2>/dev/null || true' EXIT INT TERM
wait "$web_pid"
