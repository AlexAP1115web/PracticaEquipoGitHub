#!/usr/bin/env bash
# =====================================================
# MEDICORE - ETAPA 3 DEL PIPELINE: PRUEBAS DE HUMO
# Levanta MediCore con el servidor integrado de PHP y corre pruebas/humo.php
# =====================================================
set -uo pipefail

PUERTO="${PUERTO_PRUEBAS:-8080}"
mkdir -p pruebas/salida logs sessions_data

php -S "127.0.0.1:$PUERTO" -t . -d display_errors=1 -d error_reporting=E_ALL > pruebas/salida/servidor.log 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR 2>/dev/null' EXIT

for intento in $(seq 1 20); do
    curl -s -o /dev/null "http://127.0.0.1:$PUERTO/login.php" && break
    sleep 0.5
done

php pruebas/humo.php "http://127.0.0.1:$PUERTO"
resultado=$?

# Errores de PHP que no salieron en pantalla pero sí en el registro del servidor
if grep -E "PHP (Fatal|Parse|Warning|Notice|Deprecated)" pruebas/salida/servidor.log; then
    echo "::error::El servidor registró errores de PHP (ver pruebas/salida/servidor.log)."
    resultado=1
fi

exit $resultado
