# php:8.2-apache truena en Railway con "AH00534: More than one MPM loaded",
# por eso se usa la de shinsenter (PHP-FPM + Apache, ya trae composer)
FROM shinsenter/php:8.2-fpm-apache

# mbstring no viene preinstalado en esta imagen (mysqli, gd y zip si).
RUN phpaddmod mbstring

WORKDIR /var/www/html
COPY . .

# .env vacio para que la imagen no copie el .env.example (trae localhost
# y pisa las variables de Railway)
RUN touch .env

# Carpetas que usa la app (en Railway se borran en cada deploy)
RUN mkdir -p logs uploads/perfiles sessions_data && chmod 777 sessions_data

# La imagen corre composer install sola al arrancar
