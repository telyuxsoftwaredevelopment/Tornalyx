# Tornalyx — PHP plano sobre Apache.
# El front controller vive en SGDM/frontend/index.php y el routing depende
# del .htaccess (mod_rewrite + mod_headers). El código de servidor (MVC) vive
# aparte, en SGDM/backend/, fuera del DocumentRoot.
FROM php:8.2-apache

# Extensiones PHP:
#   - pdo_mysql: conexión a la base de datos (backend/config/database.php).
#   curl, openssl y json ya vienen compilados y habilitados en la imagen
#   oficial (los usa el Mailer —envío de correo por SMTP/TLS o API HTTP— y
#   las respuestas JSON), por eso no hace falta instalarlos.
RUN docker-php-ext-install pdo_mysql

# El .htaccess usa reescritura de URLs y cabeceras de seguridad.
RUN a2enmod rewrite headers

# Define un ServerName global para evitar el warning AH00558 al arrancar
# (Apache no puede determinar el FQDN dentro del contenedor).
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# php.ini de producción (oculta errores al cliente, etc.).
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Render inyecta $PORT en tiempo de ejecución; Apache debe escuchar ahí.
# El default 80 permite correr la imagen localmente sin variables extra.
ENV PORT=80

# El DocumentRoot apunta al front controller, no a la raíz del repo.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/SGDM/frontend

# VirtualHost y puerto parametrizados con las variables de entorno de arriba
# (Apache expande ${PORT} y ${APACHE_DOCUMENT_ROOT} desde el entorno del proceso).
COPY SGDM/docker/ports.conf /etc/apache2/ports.conf
COPY SGDM/docker/vhost.conf /etc/apache2/sites-available/000-default.conf

# Código de la aplicación.
COPY . /var/www/html/

# storage/throttle debe ser escribible por Apache (control de fuerza bruta);
# sin este chown, Apache corre como www-data y no tiene permiso de escritura
# en lo que copió el COPY de arriba. Los avatares NO usan disco (Render free
# no tiene almacenamiento persistente): se guardan como BLOB en la base,
# ver add_avatar_blob.sql y PerfilController::avatar()/servirAvatar().
RUN mkdir -p /var/www/html/SGDM/backend/storage/throttle \
    && chown -R www-data:www-data /var/www/html/SGDM/backend/storage

EXPOSE 80
