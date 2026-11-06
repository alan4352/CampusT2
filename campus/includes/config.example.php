<?php
// EJEMPLO: copiar este archivo como config.php (en esta misma carpeta) y completar los datos reales.
// config.php no se sube a GitHub (tiene contraseñas y claves).
/* =====================================================================
   ARCHIVO: includes/config.php
   PARA QUÉ SIRVE: reúne los AJUSTES del sistema en un solo lugar.
   Si necesita cambiar algo de cómo funciona el registro, es aquí.
   ===================================================================== */

/* ---------------------------------------------------------------------
   MODO_PRUEBA
   true  = NO se envían correos reales. En su lugar:
             - el mensaje se guarda en data/correos_simulados.txt
             - y la contraseña/código se muestra en pantalla.
           (Úselo en su computadora, porque XAMPP no puede enviar correos.)
   false = se envían correos reales con la función mail() de PHP.
           (Úselo SOLO cuando el sitio esté en un hosting con correo.)
   AQUÍ SE CAMBIA EL MODO.
   --------------------------------------------------------------------- */
define('MODO_PRUEBA', true);

/* ---------------------------------------------------------------------
   CORREO REMITENTE: la dirección que aparece como "De:" en los correos.
   AQUÍ SE CAMBIA por el correo real de la escuela.
   --------------------------------------------------------------------- */
define('CORREO_REMITENTE', 'campus@escuela.edu.ar');

/* ---------------------------------------------------------------------
   CLAVE DE REGISTRO DE PROFESORES
   Para que cualquier persona NO pueda registrarse como profesor (y así
   reservar proyectores), el formulario pide esta clave. La escuela se la
   entrega solo a sus docentes.
   AQUÍ SE CAMBIA LA CLAVE. Si la deja vacía ('') no se pedirá (NO recomendado).
   --------------------------------------------------------------------- */
define('CLAVE_REGISTRO_PROFESOR', 'CAMBIAR-clave-de-registro');

/* ---------------------------------------------------------------------
   CONEXIÓN A LA BASE DE DATOS (MySQL / MariaDB)
   AQUÍ SE CAMBIAN los datos de conexión.
   --------------------------------------------------------------------- */
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'campusdigitaldb');
define('DB_USER', 'CAMBIAR-usuario');
define('DB_PASS', 'CAMBIAR-contraseña');

/* ---------------------------------------------------------------------
   RUTAS DE ARCHIVOS (__DIR__ es la carpeta "includes"; subimos un nivel)
   --------------------------------------------------------------------- */
define('ARCHIVO_CORREOS',  __DIR__ . '/../data/correos_simulados.txt'); // Correos del modo prueba

/* ---------------------------------------------------------------------
   PROGMESAS (programas de examen) — subpágina del campus
   ProgMesas no tiene login propio: programas.php le pasa la sesión del
   campus con un pase firmado con PROGMESAS_SECRETO.
   - PROGMESAS_URL: dónde corre ProgMesas (carpeta ../progmesas, se inicia
     con iniciar.bat o iniciar.sh). Por defecto, el mismo servidor del
     campus en el puerto 3000.
   - PROGMESAS_SECRETO: tiene que ser IGUAL a EXTERNO_JWT_SECRET en
     ../progmesas/.env. No la publique ni la comparta.
   - PROGMESAS_HORAS: cuánto dura el pase.
   --------------------------------------------------------------------- */
define('PROGMESAS_URL', 'http://' . preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost') . ':3000');
define('PROGMESAS_SECRETO', 'CAMBIAR-por-una-clave-larga-y-secreta'); // php -r "echo bin2hex(random_bytes(32));"
define('PROGMESAS_HORAS', 8);
