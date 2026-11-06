#!/bin/sh
# Arranque del campus en Render: configuración, permisos, tablas y Apache en el puerto que pide Render.
set -e
cd /var/www/html

# Configuración desde las variables de entorno (si no hay un config.php propio)
[ -f campus/includes/config.php ] || cp campus/includes/config.render.php campus/includes/config.php

# Certificado del proveedor de MySQL (texto PEM en la variable DB_SSL_CA)
# (si no es válido, se sigue sin él: la conexión va cifrada pero sin verificar el servidor)
if [ -n "$DB_SSL_CA" ]; then
    CA_ARCHIVO="$(php docker/certificado.php)"
    if [ -n "$CA_ARCHIVO" ]; then
        export DB_SSL_CA_ARCHIVO="$CA_ARCHIVO"
    fi
fi

# Carpetas donde escribe PHP (datos del modo prueba, base y PDF de ProgMesas)
mkdir -p campus/data progmesas/backend/uploads
chown -R www-data:www-data campus/data progmesas/backend

# Tablas del campus que el código no crea solo (alumnos, profesores)
php docker/crear_tablas.php || echo "[Campus] No se pudieron crear las tablas (¿están bien los datos de la base?)"

# Render indica el puerto en PORT
PUERTO="${PORT:-80}"
sed -ri "s/^Listen [0-9]+/Listen ${PUERTO}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PUERTO}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
