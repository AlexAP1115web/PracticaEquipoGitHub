#!/usr/bin/env bash
# =====================================================
# MEDICORE - VERIFICACIÓN DEL DESPLIEGUE
# Después de que CI aprueba un commit de main, Railway lo construye y lo publica.
# Este script espera a que /salud.php reporte ese commit y mide el tiempo de entrega
# (desde el push hasta que la versión atiende en producción). Falla si tarda más de lo acordado.
# Variables: URL_APP, COMMIT (sha completo), HORA_PUSH (ISO 8601), LIMITE_MINUTOS (15)
# =====================================================
set -uo pipefail

URL="${URL_APP:?Falta URL_APP}"
ESPERADA="${COMMIT:?Falta COMMIT}"
ESPERADA="${ESPERADA:0:7}"
LIMITE_MINUTOS="${LIMITE_MINUTOS:-15}"
INICIO_PUSH=$(date -d "${HORA_PUSH:-now}" +%s)
LIMITE=$((INICIO_PUSH + LIMITE_MINUTOS * 60))
publicada=""

echo "Esperando a que producción publique $ESPERADA (límite: $LIMITE_MINUTOS min desde el push)"

# Revisa al menos una vez y después cada 20 s hasta llegar al límite
while true; do
    respuesta=$(curl -s --max-time 20 "$URL/salud.php") || respuesta=""
    publicada=$(echo "$respuesta" | grep -o '"version":"[^"]*"' | cut -d'"' -f4)
    if [ "$publicada" = "$ESPERADA" ] && echo "$respuesta" | grep -q '"estado":"ok"'; then
        break
    fi
    echo "$(date -u '+%H:%M:%S') publicada: ${publicada:-sin respuesta}"
    [ "$(date +%s)" -ge "$LIMITE" ] && break
    sleep 20
done

minutos=$(awk -v s=$(( $(date +%s) - INICIO_PUSH )) 'BEGIN { printf "%.1f", s / 60 }')

if [ "$publicada" = "$ESPERADA" ]; then
    resultado="Publicada en $minutos min"
    estado=0
else
    resultado="No se publicó en $LIMITE_MINUTOS min (producción sigue en ${publicada:-sin respuesta})"
    estado=1
fi
echo "$resultado"

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    {
        echo "### Verificación del despliegue"
        echo ""
        echo "| Commit | Tiempo de entrega | Límite | Resultado |"
        echo "|---|---|---|---|"
        echo "| $ESPERADA | $minutos min | $LIMITE_MINUTOS min | $resultado |"
    } >> "$GITHUB_STEP_SUMMARY"
fi

exit $estado
