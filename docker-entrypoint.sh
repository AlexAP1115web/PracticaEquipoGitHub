#!/bin/sh
set -e

# Railway inyecta el puerto real en $PORT en tiempo de ejecución.
# Si no existe (por ejemplo corriendo el contenedor local), usa 8080.
PORT="${PORT:-8080}"

echo "Configurando Apache para escuchar en el puerto: $PORT"

sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
