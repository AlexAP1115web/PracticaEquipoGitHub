#!/usr/bin/env bash
# =====================================================
# MEDICORE - ETAPA 1 DEL PIPELINE: SINTAXIS
# Revisa con "php -l" todos los archivos PHP del proyecto.
# Uso: bash pruebas/sintaxis.sh
# =====================================================
set -uo pipefail

total=0
errores=0

while IFS= read -r -d '' archivo; do
    total=$((total + 1))
    if ! salida=$(php -l "$archivo" 2>&1); then
        errores=$((errores + 1))
        echo "::error file=${archivo#./}::$salida"
    fi
done < <(find . -name '*.php' \
    -not -path './vendor/*' \
    -not -path './node_modules/*' \
    -not -path './MediCore_Desktop/*' \
    -print0)

echo "Archivos PHP revisados: $total. Con errores de sintaxis: $errores."

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    {
        echo "### Etapa 1: sintaxis"
        echo "Archivos PHP revisados: **$total**. Con errores: **$errores**."
    } >> "$GITHUB_STEP_SUMMARY"
fi

[ "$errores" -eq 0 ]
