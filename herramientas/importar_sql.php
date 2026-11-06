<?php
/* =====================================================================
   ARCHIVO: herramientas/importar_sql.php
   PARA QUÉ SIRVE: pasar una base exportada (.sql) a MySQL en la nube
   (por ejemplo Aiven), con conexión cifrada.

   USO (en Windows, con el PHP de XAMPP):
     C:\xampp\php\php.exe herramientas\importar_sql.php campus.sql ^
         --host=xxxx.aivencloud.com --port=12345 --user=avnadmin --base=defaultdb --ca=ca.pem

   La contraseña se pide al ejecutarlo (o se toma de la variable DB_PASS).

   Antes de importar, el archivo se limpia de lo que un servicio en la nube
   no permite: CREATE DATABASE / USE (todo va a la base elegida),
   CREATE USER / GRANT / FLUSH PRIVILEGES y los DEFINER de vistas y
   procedimientos. El archivo original no se modifica.
   ===================================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sólo se usa desde la línea de comandos.');
}

// Opciones --nombre=valor (en cualquier orden) y el archivo .sql
$opciones = [];
$archivo = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $opciones[$m[1]] = $m[2] ?? true;
    } elseif ($archivo === null) {
        $archivo = $arg;
    }
}
if (!$archivo || empty($opciones['host']) || empty($opciones['user']) || empty($opciones['base'])) {
    fwrite(STDERR, "Uso: php importar_sql.php archivo.sql --host=HOST --port=PUERTO --user=USUARIO --base=BASE [--ca=ca.pem] [--probar]\n");
    exit(1);
}
if (!is_file($archivo)) {
    fwrite(STDERR, "No encuentro el archivo $archivo\n");
    exit(1);
}

$clave = getenv('DB_PASS');
if ($clave === false || $clave === '') {
    fwrite(STDOUT, 'Contraseña de ' . $opciones['user'] . ': ');
    $clave = rtrim((string) fgets(STDIN), "\r\n");
}

// ---- 1) Limpiar el archivo ----
$sql = file_get_contents($archivo);
$sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);                                     // marca BOM
$sql = preg_replace('#/\*M!.*?\*/\s*;?#s', '', $sql);                               // comandos sólo de MariaDB
$sql = preg_replace('/^\s*CREATE\s+DATABASE\b[^;]*;/mi', '', $sql);
$sql = preg_replace('/^\s*USE\s+`?[\w-]+`?\s*;/mi', '', $sql);
$sql = preg_replace('/^\s*(CREATE\s+USER|GRANT|FLUSH\s+PRIVILEGES)\b[^;]*;/mi', '', $sql);
$sql = preg_replace('/\s*DEFINER\s*=\s*`[^`]*`@`[^`]*`/i', '', $sql);
$sql = preg_replace('#/\*!50013 DEFINER=[^*]*\*/#i', '', $sql);
// Intercalaciones nuevas de MariaDB que MySQL 8 no conoce
$sql = preg_replace('/utf8mb4_uca1400_\w+/i', 'utf8mb4_unicode_ci', $sql);

$tablas = preg_match_all('/^\s*CREATE\s+TABLE\b/mi', $sql);
$filas = preg_match_all('/^\s*INSERT\s+INTO\b/mi', $sql);
echo "Archivo: $archivo ($tablas tablas, $filas sentencias INSERT)\n";

// ---- 2) Conectar (cifrado) ----
mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);
$ca = $opciones['ca'] ?? null;
if ($ca && !is_file($ca)) {
    fwrite(STDERR, "No encuentro el certificado $ca\n");
    exit(1);
}
$db->ssl_set(null, null, $ca ?: null, null, null);
$banderas = MYSQLI_CLIENT_SSL | ($ca ? 0 : MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);
$puerto = (int) ($opciones['port'] ?? 3306);
if (!@$db->real_connect($opciones['host'], $opciones['user'], $clave, $opciones['base'], $puerto, null, $banderas)) {
    fwrite(STDERR, 'No se pudo conectar: ' . mysqli_connect_error() . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');
$cifrado = $db->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch_row()[1] ?? '';
echo "Conectado a {$opciones['host']}:{$puerto}/{$opciones['base']}" . ($cifrado ? " (cifrado: $cifrado)" : ' (SIN cifrar)') . "\n";

if (isset($opciones['probar'])) {
    echo "Prueba de conexión correcta; no se importó nada.\n";
    exit(0);
}

// ---- 3) Importar ----
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$n = 0;
if ($db->multi_query($sql)) {
    do {
        $n++;
        if ($r = $db->store_result()) $r->free();
        if (!$db->more_results()) break;
        if (!$db->next_result()) break;
    } while (true);
}
if ($db->errno) {
    fwrite(STDERR, "Error en la sentencia N.º " . ($n + 1) . ": {$db->error}\n");
    if (stripos($db->error, 'primary key') !== false) {
        fwrite(STDERR, "Aiven pide clave primaria en todas las tablas: agregá una (por ejemplo id INT AUTO_INCREMENT PRIMARY KEY)\n"
            . "o desactivá sql_require_primary_key en la configuración avanzada del servicio de Aiven.\n");
    }
    exit(1);
}
$db->query('SET FOREIGN_KEY_CHECKS = 1');

echo "Listo: $n sentencias ejecutadas.\n";
$res = $db->query('SHOW TABLES');
while ($fila = $res->fetch_row()) {
    $c = $db->query('SELECT COUNT(*) FROM `' . $db->real_escape_string($fila[0]) . '`')->fetch_row()[0];
    echo "  {$fila[0]}: $c filas\n";
}
