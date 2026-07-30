# Se cambio la imagen base de php:8.2-apache a shinsenter/php:8.2-fpm-apache.
# Motivo: php:8.2-apache (con mod_php + prefork) tiene un bug conocido y
# ampliamente reportado en Railway (error "AH00534: More than one MPM
# loaded"), documentado en el foro oficial de Railway:
# https://station.railway.com/questions/more-than-one-mpm-loaded-error-on-php-8-9c836859
# La imagen de shinsenter usa PHP-FPM + Apache (mod_proxy_fcgi) en vez de
# mod_php clasico, por lo que no depende de mpm_prefork y no sufre ese
# conflicto. Ademas ya trae Composer y la mayoria de extensiones que
# necesita MediCore preinstaladas.
FROM shinsenter/php:8.2-fpm-apache

# mbstring no viene preinstalado en esta imagen (mysqli, gd y zip si).
RUN phpaddmod mbstring

WORKDIR /var/www/html
COPY . .

# Asegura que logs/ y uploads/ existan (Railway usa filesystem efimero:
# estos datos no persisten entre despliegues, pero si durante la vida
# del contenedor, suficiente para la demo).
RUN mkdir -p logs uploads/perfiles

# La propia imagen corre "composer install" automaticamente al arrancar
# si detecta composer.json (no hace falta un paso explicito para eso).
