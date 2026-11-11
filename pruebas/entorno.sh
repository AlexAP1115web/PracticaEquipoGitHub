#!/usr/bin/env bash
# =====================================================
# MEDICORE - GENERACIÓN DEL ENTORNO DE LIBERACIÓN
# Deja una máquina limpia lista para correr las pruebas: revisa PHP y sus extensiones,
# instala las dependencias de Composer, crea las carpetas que usa el sistema y
# la base de datos de prueba. En GitHub Actions lo hace ci.yml paso por paso;
# en una computadora nueva basta con este script.
# Uso: bash pruebas/entorno.sh
# Variables: DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME
# =====================================================
set -euo pipefail

echo "1. PHP"
php -v | head -1
version=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
if [ "$version" != "8.2" ]; then
    echo "   Aviso: producción usa PHP 8.2 y aquí hay PHP $version."
fi

for extension in mysqli mbstring curl gd zip openssl; do
    if ! php -m | grep -qi "^$extension$"; then
        echo "::error::Falta la extensión de PHP: $extension"
        exit 1
    fi
done
echo "   Extensiones: mysqli, mbstring, curl, gd, zip y openssl."

echo "2. Dependencias de Composer (mPDF para los reportes en PDF)"
composer install --no-interaction --no-progress --prefer-dist --quiet
echo "   vendor/ listo."

echo "3. Carpetas que usa el sistema"
mkdir -p logs uploads/perfiles sessions_data pruebas/salida
echo "   logs, uploads/perfiles, sessions_data y pruebas/salida."

echo "4. Base de datos de prueba"
bash pruebas/preparar_bd.sh

echo "Entorno de liberación listo. Ahora: bash pruebas/sintaxis.sh && bash pruebas/humo.sh"
