<?php
/* =====================================================================
   ARCHIVO: includes/db.php
   PARA QUÉ SIRVE: crea la conexión a la base de datos MySQL usando PDO.
   Los datos de conexión (IP, usuario, clave, base) están en config.php.

   USO:  $pdo = conectarBD();   // siempre devuelve la MISMA conexión
   Si falla, lanza una PDOException (login.php y registro.php la atrapan).
   ===================================================================== */
require_once __DIR__ . '/config.php';

function conectarBD() {
    // "static" hace que la conexión se cree una sola vez por página
    static $pdo = null;

    if ($pdo === null) {
        // 1) Intentamos primero con la configuración principal (ej. servidor o IP configurada)
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
                 . ';dbname=' . DB_NAME . ';charset=utf8mb4';

            $opciones = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // errores como excepciones
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // filas como arrays
                PDO::ATTR_EMULATE_PREPARES   => false,                    // consultas preparadas reales
                PDO::ATTR_TIMEOUT            => 2,                        // timeout corto si no responde la IP
            ];
            // Base en la nube (por ejemplo en Render): conexión cifrada. Con el certificado del proveedor
            // (DB_SSL_CA) se verifica el servidor; sin él, la conexión va cifrada pero sin verificar.
            if (defined('DB_SSL') && DB_SSL) {
                $ca = (defined('DB_SSL_CA') && DB_SSL_CA !== '') ? DB_SSL_CA : '/etc/ssl/certs/ca-certificates.crt';
                $opciones[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                $opciones[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = defined('DB_SSL_CA') && DB_SSL_CA !== '';
                $opciones[PDO::ATTR_TIMEOUT] = 10;
            }
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        } catch (PDOException $e) {
            // 2) Si la IP remota no responde (ej. al cambiar de Wi-Fi), usamos el MySQL local de XAMPP
            if (DB_HOST !== '127.0.0.1' && DB_HOST !== 'localhost') {
                try {
                    $pdoInit = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 3,
                    ]);
                    $pdoInit->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                    $dsnLocal = 'mysql:host=127.0.0.1;port=3306;dbname=' . DB_NAME . ';charset=utf8mb4';
                    $pdo = new PDO($dsnLocal, 'root', '', [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::ATTR_TIMEOUT            => 3,
                    ]);
                } catch (PDOException $eLocal) {
                    throw $e; // Si ambos fallan, relanzamos el error
                }
            } else {
                throw $e;
            }
        }
    }
    return $pdo;
}
