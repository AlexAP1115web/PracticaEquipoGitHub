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

# Railway inyecta el puerto real en la variable de entorno $PORT en TIEMPO
# DE EJECUCIÓN (no existe todavía durante el build). Por eso la sustitución
# se hace aquí, en el arranque del contenedor, y no con un RUN.
ENV PORT=8080
EXPOSE 8080

CMD sh -c "sed -i \"s/80/\${PORT}/g\" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && apache2-foreground"
