#!/usr/bin/env bash
# =====================================================
# MEDICORE - MONITOREO DE PRODUCCIÓN
# Revisa salud.php y dos páginas públicas; compara el tiempo de respuesta con los límites
# acordados en los niveles de servicio. Termina con error si algo no se cumple.
# Variables: URL_APP, LIMITE_SALUD_MS (1500), LIMITE_PAGINA_MS (3000)
# =====================================================
set -uo pipefail

URL="${URL_APP:?Falta URL_APP}"
LIMITE_SALUD_MS="${LIMITE_SALUD_MS:-1500}"
LIMITE_PAGINA_MS="${LIMITE_PAGINA_MS:-3000}"
CUERPO=$(mktemp)
fallas=0
filas=""
version=""

medir() {
    # Hasta 2 intentos para no alarmar por un corte de red de un segundo
    local ruta="$1" salida=""
    for intento in 1 2; do
        salida=$(curl -s -o "$CUERPO" -w "%{http_code} %{time_total}" --max-time 20 "$URL$ruta") || salida="000 20"
        [ "${salida%% *}" = "200" ] && break
        sleep 10
    done
    echo "$salida"
}

revisar() {
    local nombre="$1" ruta="$2" limite="$3"
    local salida codigo ms resultado="Correcto"

    salida=$(medir "$ruta")
    codigo="${salida%% *}"
    ms=$(awk -v s="${salida##* }" 'BEGIN { printf "%d", s * 1000 }')

    if [ "$codigo" != "200" ]; then
        resultado="Falla: respondió $codigo"
        fallas=$((fallas + 1))
    elif [ "$ms" -gt "$limite" ]; then
        resultado="Lento: más de $limite ms"
        fallas=$((fallas + 1))
    elif [ "$ruta" = "/salud.php" ] && ! grep -q '"estado":"ok"' "$CUERPO"; then
        resultado="Falla: la base de datos no responde"
        fallas=$((fallas + 1))
    fi

    # La versión se guarda aquí porque el archivo de respuesta se reutiliza en cada revisión
    if [ "$ruta" = "/salud.php" ]; then
        version=$(grep -o '"version":"[^"]*"' "$CUERPO" 2>/dev/null | head -1 | cut -d'"' -f4)
    fi

    printf "%-32s %s  %5s ms  %s\n" "$nombre" "$codigo" "$ms" "$resultado"
    filas+="| $nombre | $codigo | $ms ms | $limite ms | $resultado |"$'\n'
}

echo "Monitoreo de $URL ($(date -u '+%Y-%m-%d %H:%M UTC'))"
revisar "Salud (salud.php)" "/salud.php" "$LIMITE_SALUD_MS"
revisar "Inicio de sesión (login.php)" "/login.php" "$LIMITE_PAGINA_MS"
revisar "Portal público (home.php)" "/home.php" "$LIMITE_PAGINA_MS"

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    {
        echo "### Monitoreo de producción"
        echo ""
        echo "| Recurso | Código | Tiempo | Límite | Resultado |"
        echo "|---|---|---|---|---|"
        printf "%s" "$filas"
        echo ""
        echo "Versión publicada: ${version:-sin dato}"
    } >> "$GITHUB_STEP_SUMMARY"
fi

rm -f "$CUERPO"
[ "$fallas" -eq 0 ]
