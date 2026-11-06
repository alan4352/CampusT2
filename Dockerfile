# Campus T2 en un contenedor: PHP + Apache, con MySQL (campus, preinscripciones, proyectores) y SQLite (ProgMesas)
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev ca-certificates \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && printf 'date.timezone=America/Argentina/Buenos_Aires\nupload_max_filesize=12M\npost_max_size=14M\n' > /usr/local/etc/php/conf.d/campus.ini

COPY . /var/www/html/
RUN rm -rf /var/www/html/.git \
    && chmod -R u+rwX,go+rX /var/www/html \
    && chmod +x /var/www/html/docker/entrypoint.sh

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
