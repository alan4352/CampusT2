<?php
/**
 * conexion.php — Conexión del sistema de reservas al servidor del CAMPUS.
 *
 * Esta carpeta (ReservadeproyectoresV6/) vive dentro del campus, así que:
 *   · la SESIÓN y los PERMISOS vienen de ../includes/sesion.php  (los mismos del login del campus)
 *   · la BASE DE DATOS viene de ../includes/config.php + ../includes/db.php (DB_HOST, DB_NAME, DB_USER…)
 * 
 * Quién puede entrar:
 *   · directivo → todo (reservar, cancelar cualquier reserva, administrar proyectores)
 *   · profesor  → reservar SOLO a su nombre, cancelar SOLO sus reservas, sin administrar proyectores
 *   · cualquier otro / sin sesión → se redirige al campus principal (URL_CAMPUS)
 */

if (is_file(__DIR__ . '/../campus/includes/sesion.php')) {
    require_once __DIR__ . '/../campus/includes/sesion.php';
    require_once __DIR__ . '/../campus/includes/db.php';
    if (!defined('URL_CAMPUS')) define('URL_CAMPUS', '../campus/login.php');
    if (!defined('URL_PANEL_CAMPUS')) define('URL_PANEL_CAMPUS', '../campus/dashboard.php');
    if (!defined('URL_LOGOUT_CAMPUS')) define('URL_LOGOUT_CAMPUS', '../campus/logout.php');
} elseif (is_file(__DIR__ . '/../includes/sesion.php')) {
    require_once __DIR__ . '/../includes/sesion.php';
    require_once __DIR__ . '/../includes/db.php';
    if (!defined('URL_CAMPUS')) define('URL_CAMPUS', '../login.php');
    if (!defined('URL_PANEL_CAMPUS')) define('URL_PANEL_CAMPUS', '../dashboard.php');
    if (!defined('URL_LOGOUT_CAMPUS')) define('URL_LOGOUT_CAMPUS', '../logout.php');
} else {
    die('Error crítico: No se encontró la carpeta includes/ del Campus Virtual.');
}

/** Devuelve la conexión PDO del campus (una sola por petición). Lanza PDOException si falla. */
function conectar(): PDO
{
    static $preparada = false;
    $pdo = conectarBD();

    if (!$preparada) {
        // `dia` es TIMESTAMP: con +00:00 se guarda y se lee tal cual, sin corrimientos de zona horaria
        $pdo->exec("SET time_zone = '+00:00'");
        try {
            asegurar_estructura_proyectores($pdo);
        } catch (Throwable $e) {
            error_log('conexion.php (estructura proyectores): ' . $e->getMessage());
        }
        $preparada = true;
    }
    return $pdo;
}

function asegurar_estructura_proyectores(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `proyectores` (
        `ID` int NOT NULL AUTO_INCREMENT,
        `Nombre` varchar(150) NOT NULL,
        `Marca` varchar(100) NOT NULL,
        `Resolucion` varchar(50) DEFAULT '1080p',
        `Brillo` varchar(50) DEFAULT '3000 lm',
        `Conexiones` varchar(150) DEFAULT 'HDMI, VGA, USB',
        `Estado` varchar(50) DEFAULT 'operativo',
        `Imagen` longblob,
        PRIMARY KEY (`ID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `reservas` (
        `id` int NOT NULL AUTO_INCREMENT,
        `proyector_id` int NOT NULL,
        `docente_id` int DEFAULT NULL,
        `dia` timestamp NOT NULL,
        `hora_inicio` varchar(20) NOT NULL,
        `hora_fin` varchar(20) NOT NULL,
        `estado_reserva` varchar(50) DEFAULT 'reservado',
        `materia` varchar(150) DEFAULT '',
        `curso` varchar(50) DEFAULT '',
        `docente` varchar(150) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `proyector_id` (`proyector_id`),
        KEY `idx_docente_id` (`docente_id`),
        CONSTRAINT `fk_reservas_proyectores` FOREIGN KEY (`proyector_id`) REFERENCES `proyectores` (`ID`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $cols = $pdo->query("DESCRIBE `reservas`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('docente_id', $cols, true)) {
            $pdo->exec("ALTER TABLE `reservas` ADD COLUMN `docente_id` INT NULL DEFAULT NULL AFTER `proyector_id`, ADD INDEX `idx_reservas_docente_id` (`docente_id`)");
        }
    } catch (Throwable $e) {
    }

    // Insertar proyectores iniciales si la tabla está vacía
    $cant = (int)$pdo->query("SELECT COUNT(*) FROM `proyectores`")->fetchColumn();
    if ($cant === 0) {
        $st = $pdo->prepare("INSERT INTO `proyectores` (`Nombre`, `Marca`, `Resolucion`, `Brillo`, `Conexiones`, `Estado`, `Imagen`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $st->execute(['Proyector 1 (Audiovisuales)', 'Epson', '1920x1080 (Full HD)', '3600 lm', 'HDMI, VGA, USB', 'operativo', '']);
        $st->execute(['Proyector 2 (Laboratorio)', 'BenQ', '1280x800 (WXGA)', '3200 lm', 'HDMI, VGA', 'operativo', '']);
        $st->execute(['Proyector 3 (Biblioteca)', 'ViewSonic', '1024x768 (XGA)', '3000 lm', 'HDMI, USB', 'operativo', '']);
    }
}

/** Cabecera que la página agrega a toda petición que modifica datos (POST/PUT/DELETE). */
const PETICION_HEADER = 'reservas';

/* ── Seguridad común de las API ─────────────────────────────────── */

/**
 * Protección CSRF: una petición que modifica datos debe traer la cabecera X-Requested-With.
 * Un sitio ajeno no puede enviarla sin pasar por CORS (que este servidor no habilita), así que
 * un formulario o enlace malicioso no puede reservar/cancelar usando la sesión de otra persona.
 */
function exigir_peticion_propia(): void
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($m, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== PETICION_HEADER) {
        responder_json(['error' => 'Petición no permitida'], 403);
    }
}

/** Registro de auditoría (quién hizo qué) en el log de errores del servidor. */
function auditar(array $usuario, string $accion): void
{
    $limpio = static fn(string $t): string => preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t) ?? '';
    error_log(sprintf('[reservas] %s (%s): %s', $limpio($usuario['nombre']), $usuario['rol'], $limpio($accion)));
}

/* ── Usuario de la sesión del campus ────────────────────────────── */

/**
 * Usuario autorizado a usar las reservas: ['rol' => 'directivo'|'profesor', 'nombre' => '...']
 * o null si no hay sesión o el rol no tiene permiso (alumno).
 */
function usuario_reservas(): ?array
{
    static $usuario = false;
    if ($usuario !== false) {
        return $usuario;
    }
    $usuario = null;

    if (!tienePermiso('proyector')) {          // tabla $PERMISOS de includes/sesion.php
        return null;
    }
    $rol    = (string) $_SESSION['rol'];
    $nombre = trim((string) $_SESSION['nombre']);

    if ($rol === 'directivo') {
        $usuario = [
            'id'     => $_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? 'directivo',
            'rol'    => 'directivo',
            'nombre' => $nombre,
            'correo' => $_SESSION['correo'] ?? $_SESSION['email'] ?? ''
        ];
    } elseif ($rol === 'profesor') {
        $pdo        = conectar();
        $completo   = sql_nombre_completo($pdo);
        $profId     = (int)($_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? (is_numeric($_SESSION['id'] ?? null) ? $_SESSION['id'] : 0));
        $correoProf = strtolower(trim((string)($_SESSION['correo'] ?? $_SESSION['email'] ?? '')));

        $profFila = false;
        if ($profId > 0) {
            $st = $pdo->prepare("SELECT id, $completo AS completo, correo FROM profesores WHERE id = ? LIMIT 1");
            $st->execute([$profId]);
            $profFila = $st->fetch();
        }
        if ($profFila === false && $correoProf !== '') {
            $st = $pdo->prepare("SELECT id, $completo AS completo, correo FROM profesores WHERE correo = ? LIMIT 1");
            $st->execute([$correoProf]);
            $profFila = $st->fetch();
        }
        if ($profFila === false) {
            $st = $pdo->prepare("SELECT id, $completo AS completo, correo FROM profesores WHERE $completo = ? OR nombre = ? LIMIT 1");
            $st->execute([$nombre, $nombre]);
            $profFila = $st->fetch();
        }

        if ($profFila !== false && trim((string)($profFila['completo'] ?? '')) !== '') {
            $usuario = [
                'id'     => (int) $profFila['id'],
                'rol'    => 'profesor',
                'nombre' => (string) $profFila['completo'],
                'correo' => (string) ($profFila['correo'] ?? $correoProf)
            ];
        }
    }
    return $usuario;
}

/**
 * Expresión SQL con el nombre completo del docente, primero el nombre y luego el apellido ("Juan Roude").
 * Se adapta a la tabla `profesores` que haya: el registro del campus (usuarios.php) guarda todo en `nombre`, pero si la
 * tabla además tiene una columna `apellido` (o `apellidos`) se junta con `nombre`. Se usa tanto para identificar al
 * profesor que inicia sesión como para la lista que ve el directivo, así los dos coinciden letra por letra.
 */
function sql_nombre_completo(PDO $pdo): string
{
    static $expresion = null;
    if ($expresion === null) {
        $columnas = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM `profesores`')->fetchAll(PDO::FETCH_COLUMN));
        $apellido = in_array('apellido', $columnas, true) ? 'apellido'
                  : (in_array('apellidos', $columnas, true) ? 'apellidos' : null);   // nombre fijo: nunca viene del usuario
        $expresion = $apellido === null
            ? 'TRIM(nombre)'
            : "TRIM(CONCAT_WS(' ', NULLIF(TRIM(nombre), ''), NULLIF(TRIM($apellido), '')))";
    }
    return $expresion;
}

/** Nombres completos ("Nombre Apellido") de la tabla `profesores`, ordenados, sin repetidos ni vacíos. */
function listar_profesores(PDO $pdo): array
{
    $completo = sql_nombre_completo($pdo);
    $filas = $pdo->query("SELECT DISTINCT $completo AS completo FROM profesores ORDER BY completo")
                 ->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(
        array_map(static fn($n) => trim((string) $n), $filas),
        static fn($n) => $n !== ''
    ));
}

/** Compara dos nombres sin distinguir mayúsculas ni espacios de los extremos. */
function mismo_nombre(string $a, string $b): bool
{
    return mb_strtolower(trim($a), 'UTF-8') === mb_strtolower(trim($b), 'UTF-8');
}

/** El directivo cancela cualquier reserva; el profesor solo las que están a su nombre o ID. */
function puede_eliminar_reserva(array $usuario, $reservaOTexto): bool
{
    if ($usuario['rol'] === 'directivo') {
        return true;
    }
    if (is_array($reservaOTexto)) {
        if (!empty($reservaOTexto['docente_id']) && !empty($usuario['id'])) {
            return (int)$reservaOTexto['docente_id'] === (int)$usuario['id'];
        }
        $docenteNombre = (string)($reservaOTexto['docente'] ?? $reservaOTexto['nombre'] ?? '');
    } else {
        $docenteNombre = (string)$reservaOTexto;
    }
    return mismo_nombre($usuario['nombre'], $docenteNombre);
}

/**
 * Para los endpoints api_*.php: exige sesión de directivo o profesor y devuelve el usuario.
 * Sin sesión → 401 (la página lo detecta y vuelve al campus). Rol sin permiso → 403.
 * Con $soloDirectivo = true, un profesor también recibe 403.
 */
function exigir_usuario_api(bool $soloDirectivo = false): array
{
    exigir_peticion_propia();   // CSRF: POST/PUT/DELETE solo desde la propia página
    try {
        $u = usuario_reservas();
    } catch (Throwable $e) {
        error_log('conexion.php (usuario): ' . $e->getMessage());
        responder_json(['error' => 'Error de base de datos'], 500);
    }
    session_write_close();   // ya no se escribe en la sesión: libera el bloqueo para las demás peticiones

    if ($u === null) {
        responder_json(['error' => 'Sesión no iniciada o sin permiso', 'login' => true], 401);
    }
    if ($soloDirectivo && $u['rol'] !== 'directivo') {
        responder_json(['error' => 'Solo un directivo puede administrar los proyectores'], 403);
    }
    return $u;
}

/**
 * Si el contenido de `Imagen` es una ruta de texto (ej. "./img/samsung_projector.png") la devuelve; si es binario, null.
 */
function ruta_imagen_antigua(?string $contenido): ?string
{
    if ($contenido === null || $contenido === '') {
        return null;
    }
    $ok = preg_match('#^(?:https?://|\.{0,2}/)?[\w\-./%~ ]+\.(?:png|jpe?g|gif|webp|avif|bmp|svg)$#iD', $contenido) === 1;
    return $ok ? $contenido : null;
}

/** Lista de proyectores con el formato que usa la página. */
function listar_proyectores(PDO $pdo): array
{
    $filas = $pdo->query(
        'SELECT ID, Nombre, Marca, Resolucion, Brillo, Conexiones, Estado,
                LENGTH(Imagen) AS img_len,
                LEFT(MD5(Imagen), 10) AS img_ver,
                CASE WHEN LENGTH(Imagen) <= 300 THEN Imagen END AS img_chica
         FROM proyectores ORDER BY ID'
    )->fetchAll();

    return array_map(static function (array $r): array {
        $imagen = '';
        if ((int) $r['img_len'] > 0) {
            $ruta = ruta_imagen_antigua($r['img_chica'] !== null ? (string) $r['img_chica'] : null);
            $imagen = $ruta ?? 'api_proyectores.php?imagen=' . (int) $r['ID'] . '&v=' . $r['img_ver'];
        }
        return [
            'id'         => (int) $r['ID'],
            'nombre'     => $r['Nombre'],
            'marca'      => $r['Marca'],
            'resolucion' => $r['Resolucion'],
            'brillo'     => $r['Brillo'],
            'conexiones' => $r['Conexiones'],
            'estado'     => $r['Estado'],
            'imagen'     => $imagen,   // URL (o ruta antigua) lista para usar en <img src>
        ];
    }, $filas);
}

/* ── Utilidades para los endpoints JSON (api_*.php) ─────────────── */

/** Responde en JSON y termina. */
function responder_json($datos, int $codigo = 200)
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Cuerpo JSON de la petición como array (vacío si no es válido). */
function leer_json(): array
{
    $datos = json_decode((string) file_get_contents('php://input'), true);
    return is_array($datos) ? $datos : [];
}