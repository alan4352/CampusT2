<?php
/* =====================================================================
   ARCHIVO: includes/config.render.php
   PARA QUÉ SIRVE: configuración del campus cuando corre en Render (Docker).
   Lee todo de las variables de entorno que se cargan en Render
   (Environment). Al arrancar el contenedor, docker/entrypoint.sh lo copia
   como config.php si no hay uno.
   ===================================================================== */

function variableEntorno(string $nombre, string $porDefecto = ''): string {
    $valor = getenv($nombre);
    return ($valor === false || $valor === '') ? $porDefecto : $valor;
}

// true = no se envían correos reales: se guardan en data/correos_simulados.txt y la clave se muestra en pantalla
define('MODO_PRUEBA', variableEntorno('MODO_PRUEBA', 'true') !== 'false');
define('CORREO_REMITENTE', variableEntorno('CORREO_REMITENTE', 'campus@escuela.edu.ar'));
define('CLAVE_REGISTRO_PROFESOR', variableEntorno('CLAVE_REGISTRO_PROFESOR'));

// Base MySQL (en otro proveedor, por ejemplo Aiven)
define('DB_HOST', variableEntorno('DB_HOST', 'localhost'));
define('DB_PORT', (int) variableEntorno('DB_PORT', '3306'));
define('DB_NAME', variableEntorno('DB_NAME', 'campusdigitaldb'));
define('DB_USER', variableEntorno('DB_USER'));
define('DB_PASS', variableEntorno('DB_PASS'));
// Conexión cifrada (los MySQL en la nube la piden). DB_SSL_CA: archivo del certificado del proveedor, si lo hay.
define('DB_SSL', variableEntorno('DB_SSL', 'false') === 'true');
define('DB_SSL_CA', variableEntorno('DB_SSL_CA_ARCHIVO'));

define('ARCHIVO_CORREOS', __DIR__ . '/../data/correos_simulados.txt');

// ProgMesas corre con PHP dentro del contenedor (progmesas/api.php): no usa el puerto 3000
define('PROGMESAS_URL', 'http://localhost:3000');
define('PROGMESAS_SECRETO', variableEntorno('PROGMESAS_SECRETO'));
define('PROGMESAS_HORAS', 8);
