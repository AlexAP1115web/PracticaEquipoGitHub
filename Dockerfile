FROM php:8.2-apache

# Extensiones PHP necesarias (mysqli para MySQL, gd/mbstring/zip para mPDF)
RUN apt-get update && apt-get install -y \
        libzip-dev \
        libpng-dev \
        libonig-dev \
        unzip \
        git \
    && docker-php-ext-install mysqli gd mbstring zip \
    && a2enmod rewrite headers \
    && find /etc/apache2/mods-enabled -name 'mpm_*' -delete \
    && a2enmod mpm_prefork \
    && apache2ctl -M 2>&1 | grep -i mpm || true \
    && rm -rf /var/lib/apt/lists/*

# Composer (para instalar mpdf/mpdf definido en composer.json)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copia primero solo composer.json/lock para aprovechar cache de capas
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Copia el resto del proyecto
COPY . .

# Asegura que logs/ y uploads/ existan y sean escribibles (Railway usa
# filesystem efímero: estos datos NO persisten entre despliegues, pero
# sí durante la vida del contenedor, que es suficiente para la demo).
RUN mkdir -p logs uploads/perfiles \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Script de arranque: ajusta el puerto de Apache al que Railway asigne en
# tiempo de ejecución.
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN sed -i 's/\r$//' /docker-entrypoint.sh \
    && chmod +x /docker-entrypoint.sh

ENV PORT=8080
EXPOSE 8080

CMD ["/docker-entrypoint.sh"]
