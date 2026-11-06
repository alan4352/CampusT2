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
        preparar_estructura_una_vez($pdo);
        $preparada = true;
    }
    return $pdo;
}

/** Sube este número cuando cambie la estructura de las tablas: la migración vuelve a correr una vez. */
const VERSION_ESTRUCTURA = 2;

/**
 * Antes, CADA petición ejecutaba 2 CREATE TABLE IF NOT EXISTS + un COUNT(*). Ahora la estructura se verifica
 * una sola vez (queda una marca en la carpeta temporal del servidor); si la marca se borra, se vuelve a verificar.
 */
function preparar_estructura_una_vez(PDO $pdo): void
{
    $huella = md5(__DIR__ . '|' . (defined('DB_HOST') ? DB_HOST : '') . '|' . (defined('DB_NAME') ? DB_NAME : ''));
    $marca  = sys_get_temp_dir() . '/reservas_estructura_v' . VERSION_ESTRUCTURA . '_' . $huella . '.txt';
    if (@is_file($marca)) {
        return;
    }
    try {
        asegurar_estructura_proyectores($pdo);
    } catch (Throwable $e) {
        error_log('conexion.php (estructura proyectores): ' . $e->getMessage());
        return;   // sin marca: se reintenta en la próxima petición
    }
    @file_put_contents($marca, date('c'), LOCK_EX);
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
        `dia` timestamp NOT NULL,
        `hora_inicio` varchar(20) NOT NULL,
        `hora_fin` varchar(20) NOT NULL,
        `estado_reserva` varchar(50) DEFAULT 'reservado',
        `materia` varchar(150) DEFAULT '',
        `curso` varchar(50) DEFAULT '',
        `docente` varchar(150) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `proyector_id` (`proyector_id`),
        CONSTRAINT `fk_reservas_proyectores` FOREIGN KEY (`proyector_id`) REFERENCES `proyectores` (`ID`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Insertar proyectores iniciales si la tabla está vacía
    $cant = (int)$pdo->query("SELECT COUNT(*) FROM `proyectores`")->fetchColumn();
    if ($cant === 0) {
        $st = $pdo->prepare("INSERT INTO `proyectores` (`Nombre`, `Marca`, `Resolucion`, `Brillo`, `Conexiones`, `Estado`, `Imagen`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $st->execute(['Proyector 1 (Audiovisuales)', 'Epson', '1920x1080 (Full HD)', '3600 lm', 'HDMI, VGA, USB', 'operativo', '']);
        $st->execute(['Proyector 2 (Laboratorio)', 'BenQ', '1280x800 (WXGA)', '3200 lm', 'HDMI, VGA', 'operativo', '']);
        $st->execute(['Proyector 3 (Biblioteca)', 'ViewSonic', '1024x768 (XGA)', '3000 lm', 'HDMI, USB', 'operativo', '']);
    }

    /* ── v2: cada reserva guarda el ID del profesor (columna reservas.profesor_id) ── */
    $colsReservas = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM `reservas`')->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('profesor_id', $colsReservas, true)) {
        $pdo->exec('ALTER TABLE `reservas` ADD COLUMN `profesor_id` INT NULL DEFAULT NULL, ADD KEY `idx_reservas_profesor` (`profesor_id`)');
    }

    // La tabla `profesores` del campus necesita una columna `id`. Si ya la tiene no se toca; si no, se agrega
    // (numérica, autoincremental) sin alterar el resto de la tabla.
    try {
        $colsProf = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM `profesores`')->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array('id', $colsProf, true)) {
            $pdo->exec('ALTER TABLE `profesores` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT, ADD UNIQUE KEY `uq_profesores_id` (`id`)');
        }
    } catch (Throwable $e) {
        error_log('conexion.php (profesores.id): ' . $e->getMessage());   // sin id las reservas siguen funcionando por nombre
    }

    // Un turno de un proyector no puede estar dos veces: lo garantiza la base (evita duplicados por doble clic o carreras).
    // Si ya existe un índice único no se toca; si hay duplicados viejos el ALTER falla y solo se registra en el log.
    try {
        $unicos = $pdo->query("SHOW INDEX FROM `reservas` WHERE Non_unique = 0 AND Key_name <> 'PRIMARY'")->fetchAll();
        if (!$unicos) {
            $pdo->exec('ALTER TABLE `reservas` ADD UNIQUE KEY `uq_reserva_turno` (`proyector_id`, `dia`, `hora_inicio`)');
        }
    } catch (Throwable $e) {
        error_log('conexion.php (índice único de reservas): ' . $e->getMessage());
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
 * Usuario autorizado a usar las reservas: ['rol' => 'directivo'|'profesor', 'nombre' => '...', 'id' => int|null]
 * o null si no hay sesión o el rol no tiene permiso (alumno). 'id' es el de la tabla `profesores` (solo profesores).
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
        $usuario = ['rol' => 'directivo', 'nombre' => $nombre, 'id' => null];
    } elseif ($rol === 'profesor') {
        $usuario = identificar_profesor($nombre);
    }
    return $usuario;
}

/**
 * Busca al profesor de la sesión en la tabla `profesores`. Primero por el CORREO con el que inició sesión
 * (login.php lo guarda en $_SESSION['correo'] y en $_SESSION['id'] = 'profesor:correo'), que es único: así dos profesores
 * con el mismo nombre nunca se confunden. Si no hay correo, por nombre (y el id queda sin determinar si el nombre se repite).
 * El resultado se recuerda 10 min en la sesión para no consultar la base en cada petición.
 */
function identificar_profesor(string $nombre): ?array
{
    $correo = strtolower(trim((string) ($_SESSION['correo'] ?? '')));
    if ($correo === '') {
        $sid = (string) ($_SESSION['id'] ?? '');
        if (strncmp($sid, 'profesor:', 9) === 0) {
            $correo = strtolower(trim(substr($sid, 9)));
        }
    }
    $clave = md5($nombre . '|' . $correo);
    $c = $_SESSION['reservas_profesor'] ?? null;
    if (is_array($c) && ($c['k'] ?? null) === $clave && (time() - (int) ($c['t'] ?? 0)) < 600 && is_array($c['u'] ?? null)) {
        return $c['u'];
    }

    $pdo      = conectar();
    $completo = sql_nombre_completo($pdo);
    $idSql    = sql_id_profesor($pdo);
    $fila = false;
    if ($correo !== '') {
        $st = $pdo->prepare("SELECT $idSql AS pid, $completo AS completo FROM profesores WHERE LOWER(TRIM(correo)) = ? LIMIT 1");
        $st->execute([$correo]);
        $fila = $st->fetch();
    }
    if (!$fila) {
        // La sesión trae lo que guardó el login (`profesores.nombre`). Se acepta tanto el nombre completo como el valor de `nombre`.
        $st = $pdo->prepare("SELECT $idSql AS pid, $completo AS completo FROM profesores WHERE $completo = ? OR nombre = ? LIMIT 2");
        $st->execute([$nombre, $nombre]);
        $filas = $st->fetchAll();
        if ($filas) {
            $fila = $filas[0];
            if (count($filas) > 1) {
                $fila['pid'] = null;   // nombre repetido y sin correo: no se puede saber cuál es
            }
        }
    }
    if (!$fila || trim((string) $fila['completo']) === '') {
        return null;
    }
    $u = [
        'rol'    => 'profesor',
        'nombre' => (string) $fila['completo'],
        'id'     => $fila['pid'] !== null ? (int) $fila['pid'] : null,
    ];
    $_SESSION['reservas_profesor'] = ['k' => $clave, 't' => time(), 'u' => $u];
    return $u;
}

/** Columnas de la tabla `profesores` en minúsculas (una sola consulta por petición). */
function columnas_profesores(PDO $pdo): array
{
    static $cols = null;
    if ($cols === null) {
        $cols = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM `profesores`')->fetchAll(PDO::FETCH_COLUMN));
    }
    return $cols;
}

function profesores_tiene_id(PDO $pdo): bool
{
    return in_array('id', columnas_profesores($pdo), true);
}

/** Expresión SQL del id del profesor (NULL si la tabla no tiene columna `id`). */
function sql_id_profesor(PDO $pdo): string
{
    return profesores_tiene_id($pdo) ? 'id' : 'NULL';
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
        $columnas = columnas_profesores($pdo);
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

/** Profesores con id y correo, ordenados: [['id' => int|null, 'nombre' => 'Juan Roude', 'correo' => 'a@b.com'], ...]. */
function profesores_detalle(PDO $pdo): array
{
    $completo = sql_nombre_completo($pdo);
    $idSql    = sql_id_profesor($pdo);
    $filas = $pdo->query("SELECT $idSql AS pid, $completo AS completo, TRIM(correo) AS correo FROM profesores ORDER BY completo, correo")
                 ->fetchAll();
    $salida = [];
    foreach ($filas as $f) {
        $n = trim((string) $f['completo']);
        if ($n === '') {
            continue;
        }
        $salida[] = ['id' => $f['pid'] !== null ? (int) $f['pid'] : null, 'nombre' => $n, 'correo' => (string) $f['correo']];
    }
    return $salida;
}

/** Junta a los profesores con el mismo nombre (sin distinguir mayúsculas): [['nombre' => '...', 'correos' => ['a@b', 'c@d']], ...]. */
function docentes_agrupados(array $detalle): array
{
    $grupos = [];
    foreach ($detalle as $p) {
        $k = mb_strtolower($p['nombre'], 'UTF-8');
        if (!isset($grupos[$k])) {
            $grupos[$k] = ['nombre' => $p['nombre'], 'correos' => []];
        }
        if ($p['correo'] !== '' && !in_array($p['correo'], $grupos[$k]['correos'], true)) {
            $grupos[$k]['correos'][] = $p['correo'];
        }
    }
    return array_values($grupos);
}

/**
 * ID del profesor buscándolo por nombre completo (+ correo, si se indica). Devuelve [id|null, ambiguo].
 *  · con correo: el nombre y el correo deben ser del mismo profesor;
 *  · sin correo: solo si ese nombre pertenece a un único profesor (si hay varios → ambiguo = true).
 */
function buscar_id_profesor(PDO $pdo, string $nombre, string $correo = ''): array
{
    if (!profesores_tiene_id($pdo)) {
        return [null, false];
    }
    $completo = sql_nombre_completo($pdo);
    if ($correo !== '') {
        $st = $pdo->prepare("SELECT id FROM profesores WHERE ($completo = ? OR nombre = ?) AND LOWER(TRIM(correo)) = ? LIMIT 1");
        $st->execute([$nombre, $nombre, strtolower(trim($correo))]);
        $id = $st->fetchColumn();
        return [$id !== false ? (int) $id : null, false];
    }
    $st = $pdo->prepare("SELECT id FROM profesores WHERE $completo = ? OR nombre = ? LIMIT 2");
    $st->execute([$nombre, $nombre]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) {
        return [(int) $ids[0], false];
    }
    return [null, count($ids) > 1];
}

/** Compara dos nombres sin distinguir mayúsculas ni espacios de los extremos. */
function mismo_nombre(string $a, string $b): bool
{
    return mb_strtolower(trim($a), 'UTF-8') === mb_strtolower(trim($b), 'UTF-8');
}

/**
 * El directivo cancela cualquier reserva; el profesor solo las suyas.
 * Si la reserva y el profesor tienen ID se comparan los IDs (dos profesores con el mismo nombre no se confunden);
 * si no (reservas antiguas o escritas a mano por el directivo) se compara el nombre, como antes.
 */
function puede_eliminar_reserva(array $usuario, string $docente, ?int $profesorId = null): bool
{
    if ($usuario['rol'] === 'directivo') {
        return true;
    }
    if ($profesorId !== null && ($usuario['id'] ?? null) !== null) {
        return (int) $usuario['id'] === $profesorId;
    }
    return mismo_nombre($usuario['nombre'], $docente);
}

/**
 * Reservas de varios días en UNA consulta: ['YYYY-MM-DD' => { proyectorId: { "HHMM": { id, materia, curso, nombre, puede_eliminar } } }].
 * $desde (inclusive) y $hastaExcl (exclusive) son fechas 'YYYY-MM-DD'; todos los días del rango salen en el resultado (vacíos si no hay reservas).
 */
function reservas_por_dia(PDO $pdo, array $usuario, string $desde, string $hastaExcl): array
{
    $st = $pdo->prepare(
        "SELECT ID, proyector_id, dia, hora_inicio, materia, curso, docente, profesor_id
         FROM reservas
         WHERE dia >= ? AND dia < ? AND estado_reserva = 'reservado'"
    );
    $st->execute([$desde . ' 00:00:00', $hastaExcl . ' 00:00:00']);

    $dias = [];
    for ($d = new DateTimeImmutable($desde); $d->format('Y-m-d') < $hastaExcl; $d = $d->modify('+1 day')) {
        $dias[$d->format('Y-m-d')] = [];
    }
    foreach ($st as $r) {
        $fecha = substr((string) $r['dia'], 0, 10);
        $slot  = substr(str_replace(':', '', (string) $r['hora_inicio']), 0, 4);   // "07:20:00" → "0720"
        $dias[$fecha][(string) $r['proyector_id']][$slot] = [
            'id'      => (int) $r['ID'],
            'materia' => $r['materia'],
            'curso'   => $r['curso'],
            'nombre'  => $r['docente'],
            'puede_eliminar' => puede_eliminar_reserva($usuario, (string) $r['docente'], $r['profesor_id'] !== null ? (int) $r['profesor_id'] : null),
        ];
    }
    foreach ($dias as $fecha => $mapa) {   // objetos (no listas) aunque haya una sola clave o ninguna
        foreach ($mapa as $pid => $turnos) {
            $mapa[$pid] = (object) $turnos;
        }
        $dias[$fecha] = (object) $mapa;
    }
    return $dias;
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