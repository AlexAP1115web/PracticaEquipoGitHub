#!/usr/bin/env bash
# =====================================================
# MEDICORE - ETAPA 2 DEL PIPELINE: BASE DE DATOS DE PRUEBA
# Crea una base vacía con database/schema.sql y agrega datos de ejemplo.
# Las tablas nuevas (alergias, notas, push, mapa corporal) las crea la propia app
# con config/esquema.php en la primera visita, igual que en Railway.
# Variables: DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME
# =====================================================
set -euo pipefail

export MYSQL_PWD="$DB_PASS"
MYSQL=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --default-character-set=utf8mb4)

echo "Esperando a MySQL en $DB_HOST:$DB_PORT..."
for intento in $(seq 1 30); do
    if "${MYSQL[@]}" -e "SELECT 1" >/dev/null 2>&1; then
        break
    fi
    if [ "$intento" -eq 30 ]; then
        echo "::error::MySQL no respondió."
        exit 1
    fi
    sleep 2
done

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" "$DB_NAME" < database/schema.sql
echo "Esquema base importado en $DB_NAME."

# La base es nueva: se borran las marcas para que config/esquema.php cree las tablas nuevas
rm -f logs/.esquema_innovacion_* sessions_data/.esquema_innovacion_*

php pruebas/datos_prueba.php
